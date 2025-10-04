<?php

namespace ORM\Attribute;

use ReflectionProperty;

final class EntityProperty
{
    public const HIDDEN       = 0b00001;
    public const GUARDED      = 0b00010;

    private int $flags;

    private ReflectionProperty $propertyReflection;

    public function __construct(int $flags = 0)
    {
        $this->flags = $flags;
    }

    public function isHidden(): bool
    {
        return $this->flags & self::HIDDEN;
    }

    public function isGuarded(): bool
    {
        return $this->flags & self::GUARDED;
    }
}