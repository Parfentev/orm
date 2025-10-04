<?php

namespace ORM\Attribute;

use ORM\Entity\AbstractEntity;
use ORM\Util\StringUtil;
use Attribute;
use ReflectionClass;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_CLASS)]
final class Table
{
    /** @var Column[] */
    private array  $columns = [];
    /** @var JoinColumn[] */
    private array  $joinColumns = [];
    private array  $indexes = [];
    private string $primaryKey;

    private string          $name;
    private ReflectionClass $entityReflection;
    private AbstractEntity  $entityTemplate;
    private bool            $initAttributes = false;

    public function __construct(string $name, array $indexes = [])
    {
        $this->name    = $name;
        $this->indexes = $indexes;
    }

    /**
     * Добавляет колонку
     *
     * @param Column $column
     * @param ReflectionProperty $property
     */
    public function addColumn(Column $column, ReflectionProperty $property): void
    {
        $type = $property->getType()->getName();
        switch ($type) {
            case 'int':
            case 'string':
            case 'bool':
            case 'float':
                !$column->getType() && $column->setType($type);
                break;
            case 'DateTime':
            case 'DateTimeImmutable':
                $column->setType('datetime');
                break;
            default:
                return;
        }

        $name            = $property->getName();
        $columnName      = StringUtil::toSnakeCase($name);
        $this->columns[] = $column;

        $column->setName($columnName);
        $column->isPrimary() && $this->primaryKey = $columnName;
    }

    public function addJoinColumn(JoinColumn $column, ReflectionProperty $property): void
    {
        $name            = $property->getName();
        $columnName      = StringUtil::toSnakeCase($name);
        $this->joinColumns[] = $column;

        $column->setName($columnName);
    }

    /**
     * Запоминает класс рефлексии
     *
     * @param ReflectionClass $entityReflection
     *
     * @return self
     */
    public function setReflection(ReflectionClass $entityReflection): self
    {
        $this->entityReflection = $entityReflection;
        return $this;
    }

    /**
     * Получает классы колонок таблицы
     *
     * @return Column[]
     */
    public function getColumns(): array
    {
        if ($this->columns) {
           return $this->columns;
        }

        $this->initAttributes();

        return $this->columns;
    }

    public function getJoinColumns(): array
    {
        if ($this->joinColumns) {
            return $this->joinColumns;
        }

        $this->initAttributes();

        return $this->joinColumns;
    }

    /**
     * Получает имя таблицы
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Получает имя первичного ключа
     *
     * @return string
     */
    public function getPrimaryKey(): string
    {
        $this->getColumns();
        return $this->primaryKey;
    }

    /**
     * Получает список полей с индексами
     *
     * @return array[]
     */
    public function getIndexes(): array
    {
        return $this->indexes;
    }

    /**
     * Инициализирует сущность
     *
     * @param $data
     *
     * @return AbstractEntity
     */
    public function newEntityInstance($data): AbstractEntity
    {
        if (!isset($this->entityTemplate)) {
            /** @var AbstractEntity $template */
            $template = $this->entityReflection->newInstance();
            $this->entityTemplate = $template;
        }

        $entity = clone $this->entityTemplate;
        
        foreach ($this->getColumns() as $column) {
            $column->setValue($entity, $data[$column->getName()] ?? null);
        }

        foreach ($this->getJoinColumns() as $column) {
            $column->setValue($entity, $data[$column->getName()] ?? null);
        }

        $this->flushValue($entity);
        return $entity;
    }

    /**
     * Сбрасывает значения сущности. Требуется после занесения изменений в бд.
     *
     * @param AbstractEntity $entity
     */
    public function flushValue(AbstractEntity $entity): void
    {
        foreach ($this->getColumns() as $column) {
            $column->flushValue($entity);
        }
    }

    private function initAttributes(): void
    {
        if ($this->initAttributes) {
            return;
        }

        $sortOrder = [];

        // Поиск колонок в родительских классах
        $parentClass = $this->entityReflection->getParentClass();
        while ($parentClass !== false) {
            $parentProperties = $parentClass->getProperties(ReflectionProperty::IS_PROTECTED);
            $sortOrder        = array_merge(array_column($parentProperties, 'name'), $sortOrder);
            $parentClass      = $parentClass->getParentClass();
        }

        // Поиск колонок
        $properties = $this->entityReflection->getProperties(ReflectionProperty::IS_PROTECTED);

        // Сортировка колонок по иерархии классов
        $properties = array_filter(array_replace(
            array_flip($sortOrder),
            array_column($properties, null, 'name')
        ));

        foreach ($properties as $property) {
            $column = $joinColumn = null;

            if ($property->isStatic()) {
                continue;
            }

            $attributes = $property->getAttributes();
            foreach ($attributes as $attribute) {
                $attribute->getName() === Column::class     && $column = $attribute->newInstance();
                $attribute->getName() === JoinColumn::class && $joinColumn = $attribute->newInstance();
            }

            // Получение колонок
            if (!empty($column)) {
                $column->setReflection($property);
                $this->addColumn($column, $property);
                continue;
            }

            // удалить в php8
            if (!empty($joinColumn)) {
                $joinColumn->setReflection($property);
                $this->addJoinColumn($joinColumn, $property);
            }
        }

        $this->initAttributes = true;
    }
}