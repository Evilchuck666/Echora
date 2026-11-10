<?php

namespace App\Message;

/**
 * Tracks to convert to Opus ahead of time, for one user, in the order they will be played (POST /api/transcodes).
 * Handled in the background by App\MessageHandler\TranscodeTracksHandler, which stops as soon as the same user sends
 * a newer list.
 */
final readonly class TranscodeTracks
{
    /**
     * @param string       $userId    Identifier (RFC 4122) of the user who asked
     * @param string       $requestId Identifier of the request: only the user's latest one is still wanted
     * @param list<string> $trackIds  Identifiers (RFC 4122) of the tracks, next one first
     */
    public function __construct(
        public string $userId,
        public string $requestId,
        public array $trackIds,
    ) {
    }
}
