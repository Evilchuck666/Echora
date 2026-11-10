<?php

namespace App\Dto;

use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/transcodes: the tracks a client is about to play, next one first.
 */
final readonly class TranscodeRequest
{
    public const int MAX_TRACKS = 20;

    /** A track's id, or its IRI (/api/tracks/{id}). */
    public const string TRACK_PATTERN = '#^(?:/api/tracks/)?('.Requirement::UUID.')$#i';

    /**
     * @param list<string> $tracks
     */
    public function __construct(
        #[Assert\Count(max: self::MAX_TRACKS, maxMessage: 'At most {{ limit }} tracks per request.')]
        #[Assert\All([new Assert\Regex(self::TRACK_PATTERN, message: 'Expected the id of a track, or its IRI (/api/tracks/{id}).')])]
        public array $tracks,
    ) {
    }

    /**
     * @return list<string> Identifiers (RFC 4122) of the tracks, in the order given, each once
     */
    public function trackIds(): array
    {
        $ids = [];
        foreach ($this->tracks as $track) {
            if (1 === preg_match(self::TRACK_PATTERN, $track, $matches)) {
                $ids[strtolower($matches[1])] = true;
            }
        }

        return array_keys($ids);
    }
}
