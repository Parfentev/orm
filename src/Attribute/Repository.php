<?php

namespace ORM\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Repository
{
    public function __construct(private string $class) {}

    public function getClass(): string
    {
        return $this->class;
    }
}