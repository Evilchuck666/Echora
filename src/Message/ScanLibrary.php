<?php

namespace App\Message;

/**
 * A library scan asked for through the API, run in the background by the library worker
 * (App\MessageHandler\ScanLibraryHandler, messenger:consume library).
 */
final readonly class ScanLibrary
{
    /**
     * @param string $scanId Identifier (RFC 4122) of the App\Entity\LibraryScan to run
     */
    public function __construct(public string $scanId)
    {
    }
}
