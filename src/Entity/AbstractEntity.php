<?php

namespace ORM\Entity;

use ORM\Attribute\Entity;
use ORM\Attribute\Table;
use ORM\Manager;
use ORM\Util\StringUtil;
use BadMethodCallException;
use DateTime;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;

class AbstractEntity
{
    private array           $oldData = [];
    private ReflectionClass $reflection;
    private ?Table          $table;

    private array $propertyCache = [];

    public function __construct()
    {
        $class = get_class($this);
        $this->reflection = Manager::getReflection($class);
        $this->table      = Manager::getTable($class);

        // Заполняем сущность первоначальными значениями
        $properties = $this->reflection->getProperties(ReflectionProperty::IS_PROTECTED);
        foreach ($properties as $property) {
            $propertyName = $property->getName();

            // Пропускаем статические свойства и уже заполненные дефолтными значениями
            if ($property->isStatic() || isset($this->{$propertyName})) {
                continue;
            }

            $propertyType = $property->getType();
            if ($propertyType === null) {
                continue;
            }

            $value = null;

            // Если можно null
            if ($propertyType->allowsNull()) {
                $this->{$propertyName} = $value;
                continue;
            }

            // Приведение значения к встроенному типу
            if ($propertyType->isBuiltin()) {
                settype($value, $propertyType->getName());
                $this->{$propertyName} = $value;
            }
        }

        // Фиксируем значения
        $this->table && $this->table->flushValue($this);
    }

    /**
     * Обрабатывает вызовы несуществующих методов
     *
     * @param $name
     * @param $params
     *
     * @return self|mixed
     */
    public function __call($name, $params)
    {
        $isGetter = str_starts_with($name, 'get');
        $isSetter = str_starts_with($name, 'set');
        $message  = "Попытка вызвать несуществующий метод: $name.";

        if (!$isGetter && !$isSetter) {
            throw new BadMethodCallException($message);
        }

        // Проверка через кэш
        if (!isset($this->propertyCache[$name])) {
            $column                     = lcfirst(substr($name, 3));
            $propertyExist              = property_exists($this, $column);
            $this->propertyCache[$name] = [
                'name'            => $column,
                'property_exists' => $propertyExist
            ];
        } else {
            $column        = $this->propertyCache[$name]['name'];
            $propertyExist = $this->propertyCache[$name]['property_exists'];
        }

        if ($propertyExist) {
            if ($isGetter) {
                return $this->getter($column);
            }

            if ($isSetter) {
                return $this->setter($column, $params);
            }
        }

        throw new BadMethodCallException($message);
    }

    /**
     * Отдает значение свойства
     *
     * @param $columnName
     *
     * @return mixed
     */
    private function getter($columnName): mixed
    {
        try {
            $property = $this->reflection->getProperty($columnName);
        } catch (ReflectionException) {
            return $this->{$columnName};
        }

        $type = $property->getType();

        // Тип DateTime
        if ($type && $type->getName() === 'DateTime') {
            $value = $this->{$columnName};
            if ($type->allowsNull() && !$value) {
                return null;
            }

            $format = $params[0] ?? 'U';
            $value  = $value->format($format);
            return $format === 'U' ? (int)$value : $value;
        }

        return $this->{$columnName};
    }

    /**
     * Заполняет свойство
     *
     * @param string $column
     * @param array $params
     *
     * @return self
     */
    private function setter(string $column, array $params): self
    {
        try {
            $property = $this->reflection->getProperty($column);
        } catch (ReflectionException) {
            return $this;
        }

        $value = $params[0] ?? null;
        $type  = $property->getType();

        // Разрешен null
        if ($type->allowsNull() && is_null($value)) {
            return $this;
        }

        $typeName = $type->getName();

        // Тип DateTime
        if ($typeName === 'DateTime') {
            if ($type->allowsNull() && !$value) {
                $this->{$column} = null;
                return $this;
            }

            !$value && $value = time();
            $this->{$column} = DateTime::createFromFormat($params[1] ?? 'U', $value);
            return $this;
        }

        if ($type->isBuiltin()) {
            settype($value, $typeName); // Приведение значения к встроенному типу
        } elseif (!$value instanceof $typeName) {
            return $this;
        }

        if (!isset($this->{$column}) || $this->{$column} !== $value) {
            $this->{$column} = $value;
        }

        return $this;
    }

    public function getReflection(): ReflectionClass
    {
        return $this->reflection;
    }

    /**
     * Сбрасывает значение свойства. Требуется после занесения изменений в бд.
     *
     * @param string $propertyName
     */
    final public function flushPropertyValue(string $propertyName): void
    {
        property_exists($this, $propertyName) && $this->oldData[$propertyName] = $this->{$propertyName} ?? null;
    }

    /**
     * Получает имена свойств, которые были изменены.
     *
     * @return array
     */
    final public function getModifiedColumns(): array
    {
        $modifiedColumns = [];

        foreach ($this->oldData as $columnName => $oldValue) {
            $currentValue = $this->{$columnName} ?? null;
            $currentValue !== $oldValue && $modifiedColumns[] = $columnName;
        }

        return $modifiedColumns;
    }

    /**
     * Заполняет сущность данными из массива
     *
     * @param array $data
     *
     * @return self
     */
    final public function fromArray(array $data): self
    {
        if (!$this->table) {
            return $this;
        }

        $columns       = $this->table->getColumns();
        $propertyNames = array_map(fn($column) => $column->getPropertyName(), $columns);

        foreach ($propertyNames as $propertyName) {
            $snakeCase = StringUtil::toSnakeCase($propertyName);
            if (!isset($data[$snakeCase])) {
                continue;
            }

            $setterFunc = 'set' . ucfirst($propertyName);
            $this->{$setterFunc}($data[$snakeCase]);
        }

        return $this;
    }

    /**
     * Преобразует сущность в массив
     *
     * @param array|null $fields
     *
     * @return array
     */
    final public function toArray(?array $fields = null): array
    {
        $item = [];

        // TODO 26-04-2022 parfentev: Добавить сортировку вызова геттеров и скрытие свойств из ответа

        $properties = $this->getSortedProperties();

        foreach ($properties as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $propertyName = $property->getName();
            $field        = StringUtil::toSnakeCase($propertyName);

            // Если поле не требуется, то пропускаем
            if ($fields && $fields !== ['all'] && in_array($field, $fields)) {
                continue;
            }

            $field  = StringUtil::toSnakeCase($propertyName);
            $getter = 'get' . ucfirst($propertyName);

            $value = $this->{$getter}();
            $value instanceof AbstractEntity && $value = $value->toArray();

            $item[$field] = $value;
        }

        return $item;
    }

    /**
     * Возвращает список защищенных полей сущности в указанном формате.
     * По умолчанию поля возвращаются в camelCase.
     *
     * @param string $format Формат имени поля (snake_case или camelCase)
     *
     * @return array
     */
    public static function getFieldNames(string $format = StringUtil::FORMAT_CAMEL_CASE): array
    {
        $reflection = new ReflectionClass(static::class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PROTECTED);

        $fields = [];
        foreach ($properties as $property) {
            $propertyName  = $property->getName();
            $formattedName = StringUtil::formatCase($propertyName, $format);
            $fields[]      = $formattedName;
        }
        return $fields;
    }

    /**
     * Получает свойства класса отсортированные по иерархии наследования классов
     *
     * @return array
     */
    private function getSortedProperties(): array
    {
        $properties = $this->reflection->getProperties(ReflectionProperty::IS_PROTECTED);

        $properties = array_reverse(array_reduce($properties, function ($carry, $item) {
            $carry[$item->class][] = $item;
            return $carry;
        }, []));

        return array_merge(...array_values($properties));
    }

    public function preloadRelations(): void
    {
        // Кэш свойств с атрибутом Entity
        $properties = $this->reflection->getProperties();

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

        $data = [];
        foreach ($relations as $entityClass => $args) {
            $value = $this->{$args['getter']}();
            $value && $data[$entityClass][] = $value;
        }

        $relationsData = [];
        // Загружаем сущности одним запросом для каждого типа
        foreach ($relations as $entityClass => $args) {
            if (!empty($data[$entityClass])) {
                $relationsData[$entityClass] = Manager::getRepository($entityClass)
                    ->findAll(['id' => array_filter(array_unique($data[$entityClass]))])
                    ->pluck(null, fn($entity) => $entity->getId());
            }
        }

        foreach ($relations as $entityClass => $args) {
            $id = $this->{$args['getter']}();
            if (!empty($relationsData[$entityClass][$id])) {
                $this->{$args['setter']}($relationsData[$entityClass][$id]);
            }
        }
    }
}