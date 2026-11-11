<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The name of a person, as shown to others: 1 to 100 characters, without spaces at the start or the end.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class PersonName extends Assert\Compound
{
    public const int MAX_LENGTH = 100;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'The name cannot be empty.'),
            new Assert\Length(max: self::MAX_LENGTH, maxMessage: 'The name has at most {{ limit }} characters.'),
            new Assert\Regex('/\A\S(?:.*\S)?\z/su', message: 'The name cannot start or end with a space.'),
        ];
    }
}
