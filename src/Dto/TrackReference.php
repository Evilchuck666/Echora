<?php

namespace App\Dto;

use Symfony\Component\Routing\Requirement\Requirement;

/**
 * How request bodies point to a track: its id, or its IRI (/api/tracks/{id}).
 */
final class TrackReference
{
    public const string PATTERN = '#^(?:/api/tracks/)?('.Requirement::UUID.')$#i';

    public const string MESSAGE = 'Expected the id of a track, or its IRI (/api/tracks/{id}).';

    /**
     * @param list<string> $references Already validated against PATTERN
     *
     * @return list<string> Identifiers (RFC 4122) of the tracks, in the same order, duplicates included
     */
    public static function ids(array $references): array
    {
        $ids = [];
        foreach ($references as $reference) {
            if (1 === preg_match(self::PATTERN, $reference, $matches)) {
                $ids[] = strtolower($matches[1]);
            }
        }

        return $ids;
    }
}
