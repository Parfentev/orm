<?php

namespace App;

use App\Repository\AbstractRepository;
use App\Attribute\{Repository, Route, Table};
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use RegexIterator;
use Symfony\Component\HttpFoundation\Request;

class Manager
{
    public static function loadClasses(string $path, callable $callback)
    {
        $dirIterator = new RecursiveDirectoryIterator($path);
        $iterator    = new RecursiveIteratorIterator($dirIterator);
        $files       = new RegexIterator($iterator, '/^.+\.php$/i', RegexIterator::GET_MATCH);

        $collection = [];
        foreach ($files as $file) {
            // Получаем имя класса и директорию
            $relativePath = substr($file[0], strpos($file[0], "src/") + 4);
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

    private static function getRoutes(): array
    {
        /** @var Cache $cache */
        global $cache;
        $routes = $cache->get('routes');
        if (!is_null($routes)) {
            return $routes;
        }

        $routes = [];
        /** @var Route[] $routesClasses */
        $routesClasses = Manager::loadClasses(__DIR__ . '/Controller', function (array $collection, ReflectionClass $reflection) {
            $group = '';
            $attributes = $reflection->getAttributes(Route::class);
            if ($attributes) {
                $attribute = reset($attributes);
                $group     = $attribute->newInstance()->getPath();
            }

            $methods = array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC), fn($method) => !$method->isStatic());

            foreach ($methods as $method) {
                if ($method->isStatic()) {
                    continue;
                }

                $attributes = $method->getAttributes(Route::class);
                foreach ($attributes as $attribute) {
                    /** @var Route $route */
                    $route = $attribute->newInstance();
                    $group && $route->setGroup($group);

                    $preHandlerName = $route->getPreHandlerName();
                    if ($preHandlerName) {
                        try {
                            $route->setPreHandler([$reflection->getName(), $reflection->getMethod($preHandlerName)->getName()]);
                        } catch (ReflectionException) {}
                    }

                    $collection[] = $route->setCallback([$reflection->getName(), $method->getName()]);
                }
            }

            return $collection;
        });

        foreach ($routesClasses as $route) {
            $routes[] = [
                'path'         => $route->getGroup() . $route->getPath(),
                'methods'      => $route->getMethods(),
                'requirements' => $route->getRequirements(),
                'pre_handler'  => $route->getPreHandler(),
                'handler'      => $route->getCallback()
            ];
        }

        $cache->set('routes', $routes);
        return $routes;
    }

    private static function isPathMatch(string $requestPath, string $routePath, array $requirements): array
    {
        $pattern = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_-]*)}/', function ($matches) use ($requirements) {
            $paramName = $matches[1];
            $regex     = $requirements[$paramName] ?? '[^\/]+'; // Если нет требования, любой символ кроме "/"
            return "(?P<$paramName>$regex)";
        }, $routePath);

        $params = [];
        if (!preg_match( "~^$pattern$~", $requestPath, $matches)) {
            return $params;
        }

        foreach ($matches as $key => $value) {
            !is_int($key) && $params[$key] = $value;
        }

        return $params;
    }

    public static function getRoute(Request $request): ?array
    {
        $requestPath   = $request->getPathInfo();
        $requestMethod = $request->getMethod();

        $routes = self::getRoutes();

        Profiler::startTimer('search route');
        foreach ($routes as $route) {
            $path = $route['path'];

            if (!in_array($requestMethod, $route['methods'])) {
                continue;
            }

            if ($path === $requestPath) {
                Profiler::stopTimer();
                return $route;
            }

            if ($args = self::isPathMatch($requestPath, $path, $route['requirements'] ?? [])) {
                $route['args'] = $args;
                Profiler::stopTimer();
                return $route;
            }
        }

        Profiler::stopTimer();
        return null;
    }

    /** @var Table[]  */
    private static array $tables;
    public static function getTable(string $class): ?Table
    {
        if (isset(self::$tables[$class])) {
            return self::$tables[$class];
        }

        try {
            $reflection = self::getReflection($class);
        } catch (ReflectionException) {
            return null; // Ошибка
        }

        $attributes = $reflection->getAttributes();
        foreach ($attributes as $attribute) {
            $attribute->getName() === Table::class && $table = $attribute->newInstance();
        }

        if (empty($table)) {
            return null;  // Ошибка
        }

        /** @var Table $table */
        self::$tables[$class] = $table->setReflection($reflection);
        return $table;
    }

    /** @var ReflectionClass[]  */
    private static array $reflections;

    /**
     * @throws ReflectionException
     */
    public static function getReflection(string $class): ReflectionClass
    {
        return self::$reflections[$class] ?? self::$reflections[$class] = new ReflectionClass($class);
    }

    /** @var AbstractRepository[]  */
    private static array $repositories;
    public static function getRepository(string $class): AbstractRepository
    {
        Profiler::startTimer('search repository: ' . $class);

        if (empty(self::$repositories)) {
            self::$repositories = Manager::loadClasses(__DIR__ . '/Repository', function (array $collection, ReflectionClass $reflection) {
                $attributes = $reflection->getAttributes(Repository::class);
                if ($attributes) {
                    $attribute          = reset($attributes);
                    $class              = $attribute->newInstance()->getClass();
                    $collection[$class] = $reflection->newInstance($class);
                }

                return $collection;
            });
        }

        $repo = self::$repositories[$class] ?? self::$repositories[$class] = new AbstractRepository($class);
        Profiler::stopTimer();

        return $repo;
    }
}