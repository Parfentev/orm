<?php

namespace ORM;

use ORM\Attribute\{Repository, Table};
use ORM\Repository\AbstractRepository;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use ReflectionClass;
use ReflectionException;

class Manager
{
    private static Database $db;
    /** @var Table[]  */
    private static array $tables;
    /** @var ReflectionClass[]  */
    private static array $reflections;
    /** @var AbstractRepository[] */
    private static array $repositories;

    public static function init(object $dbConnection): void
    {
        self::$db = new Database($dbConnection);
    }

    public static function loadClasses(string $path, callable $callback)
    {
        $dirIterator = new RecursiveDirectoryIterator($path);
        $iterator    = new RecursiveIteratorIterator($dirIterator);
        $files       = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        $collection = [];
        foreach ($files as $file) {
            // Получаем имя класса и директорию
            $relativePath = substr($file[0], strpos($file[0], 'src/') + 4);
            $pathParts    = explode('/', $relativePath);
            if (!$pathParts) {
                continue;
            }

            $class     = substr(array_pop($pathParts), 0, -4);
            $namespace = implode('\\', $pathParts);

            try {
                $reflection = new ReflectionClass("App\\$namespace\\$class");
            } catch (ReflectionException) {
                return []; // Ошибка
            }

            $collection = $callback($collection, $reflection);
        }

        return $collection;
    }

    public static function getTable(string $class): ?Table
    {
        if (isset(self::$tables[$class])) {
            return self::$tables[$class];
        }

        try {
            $reflection = self::getReflection($class);
        } catch (ReflectionException) {
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

    public static function getRepository(string $class): AbstractRepository
    {
        if (empty(self::$repositories)) {
            self::$repositories = self::loadClasses(APP_PATH . '/Repository', function (array $collection, ReflectionClass $reflection) {
                $attributes = $reflection->getAttributes(Repository::class);
                if ($attributes) {
                    $attribute          = reset($attributes);
                    $class              = $attribute->newInstance()->getClass();
                    $collection[$class] = $reflection->newInstance($class);
                }

                return $collection;
            });
        }

        return self::$repositories[$class] ?? self::$repositories[$class] = new AbstractRepository($class);
    }

    public static function getDatabase(): Database
    {
        return self::$db;
    }
}