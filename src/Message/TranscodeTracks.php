<?php

namespace App\Message;

/**
 * Tracks to convert to Opus ahead of time for one device of a user, in the order they will be played
 * (POST /api/transcodes). Handled in the background by App\MessageHandler\TranscodeTracksHandler: one track per
 * message, the rest sent back to the queue, so the lists of every device take turns. Dropped as soon as the same
 * device sends a newer list.
 */
final readonly class TranscodeTracks
{
    /**
     * @param string       $userId    Identifier (RFC 4122) of the user who asked
     * @param string       $deviceId  Identifier of the device, chosen by the client
     * @param string       $requestId Identifier of the request: only the device's latest one is still wanted
     * @param list<string> $trackIds  Identifiers (RFC 4122) of the tracks still to convert, next one first
     */
    public function __construct(
        public string $userId,
        public string $deviceId,
        public string $requestId,
        public array $trackIds,
    ) {
    }
}
