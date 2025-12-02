<?php

namespace ORM\Entity;

use App\Exception\NotFoundException;
use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use ORM\Attribute\Entity;
use ORM\Manager;
use ReflectionAttribute;

class Collection implements Countable, IteratorAggregate, ArrayAccess
{
    private int $total = 0;

    /**
     * @param string $entityClass
     * @param AbstractEntity[] $collection
     */
    public function __construct(private string $entityClass, private array $collection = []) {}

    public function setTotal(int $value): void
    {
        $this->total = $value;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * Применяет пользовательскую функцию к каждому члену коллекции
     *
     * @param callable $callback
     *
     * @return self
     * @see array_walk
     */
    public function walk(callable $callback): self
    {
        array_walk($this->collection, $callback);
        return $this;
    }

    public function map(callable $callback): array
    {
        return array_filter(array_map($callback, $this->collection));
    }

    /**
     * Извлекает значения
     *
     * @param callable|null $callbackValue Если оставить null, будет использована вся сущность
     * @param callable|null $callbackIndex Если оставить null, то результат будет нумерованный массив
     *
     * @return array
     */
    public function pluck(?callable $callbackValue = null, ?callable $callbackIndex = null): array
    {
        return $this->reduce(function ($carry, AbstractEntity $entity) use ($callbackValue, $callbackIndex) {
            $value = $callbackValue ? $callbackValue($entity) : $entity;

            $callbackIndex
                ? $carry[$callbackIndex($entity)] = $value
                : $carry[] = $value;

            return $carry;
        });
    }

    public function reduce(callable $callback, array $initial = []): array
    {
        return array_reduce($this->collection, $callback, $initial);
    }

    public function __toString(): string
    {
        return $this->count() > 0 ? '1' : '';
    }

    // Методы Countable, IteratorAggregate, ArrayAccess

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->collection);
    }

    public function offsetExists($offset): bool
    {
        return isset($this->collection[$offset]);
    }

    public function offsetGet($offset): ?AbstractEntity
    {
        return $this->collection[$offset] ?? null;
    }

    public function offsetSet($offset, $value): void
    {
        is_null($offset)
            ? $this->collection[] = $value
            : $this->collection[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        unset($this->collection[$offset]);
    }

    public function count(): int
    {
        return count($this->collection);
    }

    public function toArray(?callable $callback = null): array
    {
        $this->preloadRelations();
        return $this->map($callback ?? fn (AbstractEntity $entity) => $entity->toArray());
    }// Убедитесь, что вы правильно подключили атрибут Entity

    public function preloadRelations(): void
    {
        if (empty($this->collection)) {
            return; // Нет элементов в коллекции
        }

        // Кэш свойств с атрибутом Entity
        $reflection = reset($this->collection)->getReflection();
        $properties = $reflection->getProperties();

        // Группируем все сущности по типу
        $relations = [];
        foreach ($properties as $property) {
            $name = $property->getName();
            $attributes = $property->getAttributes(Entity::class);

            if (empty($attributes)) {
                continue;
            }

            /** @var ReflectionAttribute $attribute */
            $attribute = reset($attributes);

            $args = $attribute->getArguments();
            if ($entityClass = $args['class']) {
                $relations[$entityClass] = [
                    'name'        => $name,
                    'foreign_key' => $args['foreignKey'],
                    'setter'      => 'set' . ucfirst($name),
                    'getter'      => 'get' . ucfirst($args['foreignKey'])
                ];
            }
        }

        if (!$relations) {
            return;
        }

        $data = $this->reduce(function (array $carry, AbstractEntity $entity) use ($relations) {
            foreach ($relations as $entityClass => $args) {
                $carry[$entityClass][] = $entity->{$args['getter']}();
            }
            return $carry;
        });

        $relationsData = [];
        // Загружаем сущности одним запросом для каждого типа
        foreach ($relations as $entityClass => $args) {
            if (!empty($data[$entityClass])) {
                $ids = array_filter(array_unique($data[$entityClass]));
                $ids && $relationsData[$entityClass] = Manager::getRepository($entityClass)
                    ->findAll(['id' => $ids])
                    ->pluck(null, fn($entity) => $entity->getId());
            }
        }

        foreach ($this->collection as $entity) {
            foreach ($relations as $entityClass => $args) {
                $id = $entity->{$args['getter']}();
                if (!empty($relationsData[$entityClass][$id])) {
                    $entity->{$args['setter']}($relationsData[$entityClass][$id]);
                }
            }
        }
    }
}