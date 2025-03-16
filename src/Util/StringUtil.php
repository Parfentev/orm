<?php

namespace ORM\Util;

class StringUtil
{
    const FORMAT_SNAKE_CASE = 'snake_case';
    const FORMAT_CAMEL_CASE = 'camelCase';

    /**
     * Converts text to CamelCase.
     *
     * @param string $key
     * @param bool $ucfirst
     *
     * @return string
     */
    public static function toCamelCase(string $key, bool $ucfirst = false): string
    {
        $key = ucwords(str_replace(['-', '_'], ' ', $key));
        $key = str_replace(' ', '', $key);
        return $ucfirst ? ucfirst($key) : lcfirst($key);
    }

    /**
     * Converts a string to snake_case.
     *
     * @param string $key
     *
     * @return string
     */
    public static function toSnakeCase(string $key): string
    {
        return ltrim(strtolower(
            preg_replace('/[A-Z]([A-Z](?![a-z]))*/', '_$0', $key)
        ), '_');
    }

    public static function formatCase(string $value, string $case = self::FORMAT_CAMEL_CASE): string
    {
        switch ($case) {
            case self::FORMAT_CAMEL_CASE:
                $value = self::toCamelCase($value);
                break;
            case self::FORMAT_SNAKE_CASE:
                $value = self::toSnakeCase($value);
                break;
        }

        return $value;
    }
}