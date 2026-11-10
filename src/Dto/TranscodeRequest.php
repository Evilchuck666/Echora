<?php

namespace App\Dto;

use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/transcodes: the device asking, and the tracks it is about to play, next one first.
 */
final readonly class TranscodeRequest
{
    public const int MAX_TRACKS = 20;

    /** A track's id, or its IRI (/api/tracks/{id}). */
    public const string TRACK_PATTERN = '#^(?:/api/tracks/)?('.Requirement::UUID.')$#i';

    /**
     * @param string       $device Identifier of the device (a UUID the client generates once and keeps): each device
     *                             of an account has its own list
     * @param list<string> $tracks
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Send the id of the device: a UUID generated once and kept by the client.')]
        #[Assert\Uuid(message: 'The id of the device must be a UUID, generated once and kept by the client.')]
        public string $device,
        #[Assert\Count(max: self::MAX_TRACKS, maxMessage: 'At most {{ limit }} tracks per request.')]
        #[Assert\All([new Assert\Regex(self::TRACK_PATTERN, message: 'Expected the id of a track, or its IRI (/api/tracks/{id}).')])]
        public array $tracks,
    ) {
    }

    public function deviceId(): string
    {
        return strtolower($this->device);
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
