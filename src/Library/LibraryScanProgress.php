<?php

namespace App\Library;

use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the progress of a running scan to its App\Entity\LibraryScan, for GET /api/library/scan: the scanner calls it
 * for every file, album and artist, and it writes at most once a second, plus at every new phase and at the end of
 * one. With SQL, not the entity manager, which the scanner clears as it goes.
 */
final class LibraryScanProgress
{
    private const float INTERVAL_SECONDS = 1.0;

    private ?ScanPhase $phase = null;
    private float $writtenAt = 0.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
        private readonly Uuid $scanId,
    ) {
    }

    public function __invoke(ScanPhase $phase, int $done, int $total): void
    {
        $now = (float) $this->clock->now()->format('U.u');
        if ($phase === $this->phase && $done < $total && $now - $this->writtenAt < self::INTERVAL_SECONDS) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE library_scan SET phase = ?, phase_done = ?, phase_total = ? WHERE id = ?',
            [$phase->value, $done, $total, $this->scanId->toRfc4122()],
        );
        $this->phase = $phase;
        $this->writtenAt = $now;
    }
}
