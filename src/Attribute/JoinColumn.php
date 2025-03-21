<?php

namespace ORM\Attribute;

use Attribute;
use ORM\Entity\AbstractEntity;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class JoinColumn
{
    private ReflectionProperty $propertyReflection;

    public function __construct(
        public string $name,
        public string $targetTable,
        public string $targetColumn,
        public string $refTargetColumn,
        public ?string $refColumn = null
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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $value): void
    {
        $this->name = $value;
    }

    /**
     * Заполняет колонку сущности, не вызывая сеттер
     *
     * @param AbstractEntity $entity
     * @param mixed $value
     */
    public function setValue(AbstractEntity $entity, $value): void
    {
        isset($value) && $this->propertyReflection->setValue($entity, $value);
    }

    public function getTargetTable(): string
    {
        return $this->targetTable;
    }

    public function getTargetColumn(): string
    {
        return $this->targetColumn;
    }

    public function getRefTargetColumn(): string
    {
        return $this->refTargetColumn;
    }

    public function getRefColumn(): ?string
    {
        return $this->refColumn;
    }
}