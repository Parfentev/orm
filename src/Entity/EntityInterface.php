<?php

namespace App\Entity;

interface EntityInterface
{
    public function init();

    public function toArray(?array $fields = null): array;
}