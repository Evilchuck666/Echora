<?php

namespace App\MessageHandler;

use App\Library\LibraryScans;
use App\Message\ScanLibrary;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Runs a library scan asked for through the API, in the library worker (messenger:consume library, started by
 * docker-entrypoint): never in a worker that serves HTTP requests.
 */
#[AsMessageHandler]
final readonly class ScanLibraryHandler
{
    public function __construct(private LibraryScans $scans)
    {
    }

    public function __invoke(ScanLibrary $message): void
    {
        $this->scans->run(Uuid::fromString($message->scanId));
    }
}
