<?php

namespace App\Doctrine;

/**
 * Typed values read with plain SQL (DBAL returns mixed).
 */
final class SqlValue
{
    public static function int(mixed $value): int
    {
        \assert(is_numeric($value), 'A number was expected from the database.');

        return (int) $value;
    }

    public static function string(mixed $value): string
    {
        \assert(\is_string($value), 'A string was expected from the database.');

        return $value;
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<int>
     */
    public static function ints(array $values): array
    {
        return array_map(self::int(...), $values);
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<string>
     */
    public static function strings(array $values): array
    {
        return array_map(self::string(...), $values);
    }
}
