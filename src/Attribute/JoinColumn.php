<?php

namespace App\Attribute;

use Attribute;
use RB\TableProviders\Entity\AbstractEntity;
use ReflectionProperty;

#[Attribute(Attribute::TARGET_PROPERTY)]
class JoinColumn
{
    public string  $name;
    public string  $targetTable;
    public string  $targetColumn;
    public string  $refTargetColumn;
    public ?string $refColumn;

    private ReflectionProperty $propertyReflection;

    public function __construct(
        string $targetTable,
        string $targetColumn,
        string $refTargetColumn,
        string $refColumn = null
    ) {
        $this->targetTable     = $targetTable;
        $this->targetColumn    = $targetColumn;
        $this->refTargetColumn = $refTargetColumn;
        $this->refColumn       = $refColumn;
    }

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

    public function setName(string $value)
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
        $this->propertyReflection->setAccessible(true);
        isset($value) && $this->propertyReflection->setValue($entity, $value);
        $this->propertyReflection->setAccessible(false);
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