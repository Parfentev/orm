<?php

namespace ORM;

use ORM\Annotation\Table;
use ORM\Repository\AbstractRepository;
use ReflectionClass;
use ReflectionException;

class Manager
{
    /** @var Table[]  */
    private static array $tables;

    /** @var ReflectionClass[]  */
    private static array $reflections;

    public static function getTable(string $class): ?Table
    {
        if (isset(self::$tables[$class])) {
            return self::$tables[$class];
        }

        try {
            $reflection = self::getReflection($class);
        } catch (ReflectionException $ex) {
            return null; // Error
        }

        $attributes = $reflection->getAttributes();
        foreach ($attributes as $attribute) {
            $attribute->getName() === Table::class && $table = $attribute->newInstance();
        }

        if (empty($table)) {
            return null;  // Error
        }

        /** @var Table $table */
        self::$tables[$class] = $table->setReflection($reflection);
        return $table;
    }

    /**
     * @throws ReflectionException
     */
    public static function getReflection(string $class): ReflectionClass
    {
        return self::$reflections[$class] ?? self::$reflections[$class] = new ReflectionClass($class);
    }
}