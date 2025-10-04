<?php

namespace ORM\Attribute;

use Attribute;
use DateTimeInterface;
use ORM\Entity\AbstractEntity;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class Column
{
    public const PRIMARY       = 0b00001;
    public const AUTO_GENERATE = 0b00010;
    public const REQUIRED      = 0b00100;
    public const UNIQUE        = 0b01000;
    public const INDEXED       = 0b10000;

    private ReflectionProperty $propertyReflection;

    public function __construct(
        private readonly int $flags = 0,
        private string $type = '',
        private string $name = '',
        private readonly ?int $length = null,
        private readonly int|null|string $default = null,
        private readonly string $comment = '',
        private readonly ?string $foreignEntity = null
    ) {}

    /**
     * Запоминает класс рефлексии
     *
     * @param ReflectionProperty $propertyReflection
     */
    public function setReflection(ReflectionProperty $propertyReflection): void
    {
        $this->propertyReflection = $propertyReflection;
    }

    /**
     * Получает описание колонки
     *
     * @return string
     */
    public function getColumnDefinition(): string
    {
        $type = $this->getType();

        $columnDefinition = "$this->name $type";

        isset($this->default) && $columnDefinition .= is_string($this->default)
            ? " DEFAULT '$this->default'"
            : " DEFAULT $this->default";

        if (!($this->isPrimary())) {
            $this->isRequired() && $columnDefinition .= ' NOT';    // Обработка флага REQUIRED
                                   $columnDefinition .= ' NULL';
            $this->isUnique()   && $columnDefinition .= ' UNIQUE'; // Обработка флага UNIQUE
        }

        $this->isAutoGenerate() && $columnDefinition .= ' AUTO_INCREMENT'; // Обработка флага AUTO_GENERATE
        $this->isPrimary()      && $columnDefinition .= ' PRIMARY KEY';    // Обработка флага PRIMARY

        $this->comment && $columnDefinition .= " COMMENT '$this->comment'";

        return $columnDefinition;
    }

    /**
     * Получает связь колонки
     *
     * @param string $tableName
     *
     * @return string
     */
    public function getColumnConstraint(string $tableName): string
    {
        if (!$this->foreignEntity) {
            return '';
        }

        $entityTable = \RB\Database\Helper::getEntityTableClass($this->foreignEntity);

        $entityTableName = $entityTable->getName();
        $primaryKey      = $entityTable->getPrimaryKey();

        return "CONSTRAINT {$tableName}_{$entityTableName}_{$primaryKey}_fk\r\n"
            . "        FOREIGN KEY ($this->name) REFERENCES $entityTableName ($primaryKey)";
    }

    public function getForeignKeyName(string $tableName): string
    {
        if (!$this->foreignEntity) {
            return '';
        }

        $entityTable = \RB\Database\Helper::getEntityTableClass($this->foreignEntity);

        $entityTableName = $entityTable->getName();
        $primaryKey      = $entityTable->getPrimaryKey();

        return "{$tableName}_{$entityTableName}_{$primaryKey}_fk";
    }

    public function getType(): string
    {
        $type = $this->type;

        $isUnsigned = str_contains($type, 'unsigned');
        $isUnsigned && $type = trim(str_replace('unsigned', "", $type));

        switch ($type) {
            case 'string':
                $type = $this->length ? "varchar($this->length)" : 'text';
                break;
            case 'bool':
                $type = 'tinyint(1)';
                break;
            case 'int':
                $type = 'int unsigned';
                break;
            case 'float':
                $type = 'double';
                break;
            case 'varchar':
            case 'tinyint':
                $type .= $this->length ? "($this->length)" : '';
                break;
        }

        $isUnsigned && $type .= ' unsigned';

        return $type;
    }

    public function setType(string $value)
    {
        $this->type = $value;
    }

    public function getDefault()
    {
        return $this->default;
    }

    public function getPropertyName(): string
    {
        return $this->propertyReflection->getName();
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value)
    {
        $this->name = $value;
    }

    public function isPrimary(): bool
    {
        return $this->flags & self::PRIMARY;
    }

    public function isRequired(): bool
    {
        return $this->flags & self::REQUIRED;
    }

    public function isUnique(): bool
    {
        return $this->flags & self::UNIQUE;
    }

    public function isAutoGenerate(): bool
    {
        return $this->flags & self::AUTO_GENERATE;
    }

    public function isIndexed(): bool
    {
        return $this->flags & self::INDEXED;
    }

    /**
     * Возвращает значение колонки из сущности, не вызывая геттер
     *
     * @param AbstractEntity $entity
     *
     * @return mixed
     */
    public function getValue(AbstractEntity $entity)
    {
        $value = $this->propertyReflection->isInitialized($entity)
            ? $this->propertyReflection->getValue($entity)
            : null;

        // При возможности хранения null преобразовываем пустые строки
        if (!$this->isRequired()) {
            is_string($value) && !$value && $value = null;
        }

        // Тип колонки и значения datetime
        if ($this->getType() === 'datetime' && $value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * Заполняет колонку сущности, не вызывая сеттер
     *
     * @param AbstractEntity $entity
     * @param mixed $value
     */
    public function setValue(AbstractEntity $entity, $value): void
    {
        $type     = $this->propertyReflection->getType();
        $typeName = $type->getName();

        // Тип колонки datetime
        if (in_array($typeName, ['DateTime', 'DateTimeImmutable']) && $this->getType() === 'datetime') {
            /** @var DateTimeInterface $typeName*/
            $value = $typeName::createFromFormat('Y-m-d H:i:s', $value);
            if (!$value) {
                return;
            }
        }

        isset($value) && $this->propertyReflection->setValue($entity, $value);
    }

    /**
     * Сбрасывает значение колонки в сущности. Требуется после занесения изменений в бд.
     *
     * @param AbstractEntity $entity
     */
    public function flushValue(AbstractEntity $entity): void
    {
        $entity->flushPropertyValue($this->propertyReflection->getName());
    }
}