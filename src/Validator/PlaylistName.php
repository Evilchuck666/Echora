<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The name of a playlist: 1 to 100 characters, without spaces at the start or the end. Not unique.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD)]
final class PlaylistName extends Assert\Compound
{
    public const int MAX_LENGTH = 100;

    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'The name of a playlist cannot be empty.'),
            new Assert\Length(max: self::MAX_LENGTH, maxMessage: 'The name of a playlist has at most {{ limit }} characters.'),
            new Assert\Regex('/\A\S(?:.*\S)?\z/su', message: 'The name of a playlist cannot start or end with a space.'),
        ];
    }
}
