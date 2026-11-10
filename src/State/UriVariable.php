<?php

namespace App\State;

/**
 * The value of a URI variable as a string: API Platform gives the raw string, or a Uuid it already made of it.
 */
final class UriVariable
{
    /**
     * @param array<string, mixed> $uriVariables
     */
    public static function string(array $uriVariables, string $name): string
    {
        $value = $uriVariables[$name] ?? '';

        return \is_string($value) || $value instanceof \Stringable ? (string) $value : '';
    }
}
