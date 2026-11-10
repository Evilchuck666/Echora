<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/transcodes: the device asking, and the tracks it is about to play, next one first.
 */
final readonly class TranscodeRequest
{
    public const int MAX_TRACKS = 20;

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
        #[Assert\All([new Assert\Regex(TrackReference::PATTERN, message: TrackReference::MESSAGE)])]
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
        return array_values(array_unique(TrackReference::ids($this->tracks)));
    }
}
