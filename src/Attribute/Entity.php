<?php

namespace ORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Entity
{
    public function __construct(
        private string $foreignKey,
        private string $class
    ) {}
}