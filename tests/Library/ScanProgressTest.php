<?php

namespace App\Tests\Library;

use App\Entity\LibraryScan;
use App\Library\LibraryScanner;
use App\Library\LibraryScanProgress;
use App\Library\ScanPhase;
use App\Library\ScanTrigger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * A scan tells how far it got, phase by phase, and its progress is written to it for GET /api/library/scan.
 */
final class ScanProgressTest extends KernelTestCase
{
    public function testTheScannerGoesThroughItsPhasesInOrderCountingUpToTheEndOfEach(): void
    {
        self::bootKernel();
        $calls = [];

        self::getContainer()->get(LibraryScanner::class)->scan(download: false, progress: static function (ScanPhase $phase, int $done, int $total) use (&$calls): void {
            $calls[] = [$phase, $done, $total];
        });

        $phases = array_values(array_unique(array_map(static fn (array $call): string => $call[0]->value, $calls)));
        self::assertSame(['listing', 'files', 'covers', 'artist_images'], $phases);
        foreach ([ScanPhase::Files, ScanPhase::Covers, ScanPhase::ArtistImages] as $phase) {
            $ofPhase = array_values(array_filter($calls, static fn (array $call): bool => $phase === $call[0]));
            $done = array_column($ofPhase, 1);
            $sorted = $done;
            sort($sorted);
            self::assertSame($sorted, $done, "$phase->value never goes back");
            [, $lastDone, $lastTotal] = $ofPhase[\count($ofPhase) - 1];
            self::assertSame($lastTotal, $lastDone, "$phase->value ends done");
            self::assertGreaterThan(0, $lastTotal);
        }
        self::assertSame(5, array_values(array_filter($calls, static fn (array $call): bool => ScanPhase::Files === $call[0]))[0][2], 'The five audio files of the fixtures.');
    }

    public function testTheProgressIsWrittenAtMostOnceASecondExceptAtTheStartAndEndOfAPhase(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $scan = new LibraryScan(false, ScanTrigger::Api);
        $scan->start();
        $entityManager->persist($scan);
        $entityManager->flush();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $clock = new MockClock('2026-11-13 12:00:00');
        $progress = new LibraryScanProgress($connection, $clock, $scan->getId());

        $progress(ScanPhase::Files, 0, 10);
        self::assertSame(['files', 0, 10], $this->stored($connection, $scan));

        $progress(ScanPhase::Files, 1, 10);
        self::assertSame(['files', 0, 10], $this->stored($connection, $scan), 'Within the same second: not written.');

        $clock->sleep(1.5);
        $progress(ScanPhase::Files, 2, 10);
        self::assertSame(['files', 2, 10], $this->stored($connection, $scan));

        $progress(ScanPhase::Files, 10, 10);
        self::assertSame(['files', 10, 10], $this->stored($connection, $scan), 'The end of a phase, at once.');

        $progress(ScanPhase::Covers, 0, 3);
        self::assertSame(['covers', 0, 3], $this->stored($connection, $scan), 'A new phase, at once.');
    }

    /**
     * @return array{mixed, mixed, mixed}
     */
    private function stored(Connection $connection, LibraryScan $scan): array
    {
        $row = $connection->fetchAssociative('SELECT phase, phase_done, phase_total FROM library_scan WHERE id = ?', [$scan->getId()->toRfc4122()]);
        self::assertIsArray($row);

        return [$row['phase'], $row['phase_done'], $row['phase_total']];
    }
}
