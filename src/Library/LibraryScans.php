<?php

namespace App\Library;

use App\Entity\LibraryScan;
use App\Message\ScanLibrary;
use App\Repository\LibraryScanRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Library scans, recorded as App\Entity\LibraryScan, one at a time: asked for through the API (queued for the library
 * worker), or run at once by app:library:scan (from the console or the nightly job).
 *
 * While a scan runs, its phase and progress are written to it (LibraryScanProgress). Asking for a scan while another is
 * queued or running is refused. A scan runs under a lock (symfony/lock, PostgreSQL
 * advisory): one that is still "running" while nobody holds the lock was interrupted (the container stopped), and is
 * marked failed when the next scan is asked for, so it never blocks the others.
 */
final readonly class LibraryScans
{
    private const string RUN_LOCK = 'library-scan';

    private const string REQUEST_LOCK = 'library-scan-request';

    /** A scan still queued after this was lost (its worker never came): it no longer blocks the others. */
    private const int QUEUED_AT_MOST_SECONDS = 86400;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LibraryScanRepository $scans,
        private LibraryScanner $scanner,
        private MessageBusInterface $bus,
        private LockFactory $locks,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Queues a scan for the library worker.
     *
     * @throws ScanAlreadyRequested
     */
    public function request(bool $full): LibraryScan
    {
        $scan = $this->record($full, ScanTrigger::Api);
        $this->bus->dispatch(new ScanLibrary($scan->getId()->toRfc4122()));

        return $scan;
    }

    /**
     * Scans now, in this process (app:library:scan).
     *
     * @param string|null $directory Folder to scan instead of the music library
     *
     * @throws ScanAlreadyRequested
     */
    public function runNow(ScanTrigger $trigger, bool $full, bool $download = true, ?string $directory = null): LibraryScan
    {
        $scan = $this->record($full, $trigger);

        return $this->run($scan->getId(), $download, $directory) ?? $scan;
    }

    /**
     * Runs a queued scan, after the one running, if any. Nothing happens if it is no longer queued (a message handled
     * twice).
     *
     * @return LibraryScan|null The scan, finished or failed; null if it doesn't exist
     */
    public function run(Uuid $id, bool $download = true, ?string $directory = null): ?LibraryScan
    {
        $lock = $this->locks->createLock(self::RUN_LOCK);
        $lock->acquire(true);
        try {
            $scan = $this->scans->find($id);
            if (null === $scan || ScanStatus::Queued !== $scan->getStatus()) {
                return $scan;
            }
            $scan->start();
            $this->entityManager->flush();

            $report = null;
            $error = null;
            try {
                $progress = new LibraryScanProgress($this->entityManager->getConnection(), $this->clock, $id);
                $report = $this->scanner->scan($directory, $scan->isFull(), $download, $progress);
            } catch (LibraryUnavailable $e) {
                $error = $e->getMessage();
            } catch (\Throwable $e) {
                $this->logger->error('The library scan {id} failed: {reason}', ['id' => $id->toRfc4122(), 'reason' => $e->getMessage(), 'exception' => $e]);
                $error = 'The scan stopped on an unexpected error: '.$e->getMessage();
            }

            // The scanner clears the entity manager as it goes: load the scan again.
            $scan = $this->scans->find($id) ?? throw new \LogicException('A running scan is never deleted.');
            null !== $report ? $scan->finish($report) : $scan->fail((string) $error);
            $this->entityManager->flush();

            return $scan;
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws ScanAlreadyRequested
     */
    private function record(bool $full, ScanTrigger $trigger): LibraryScan
    {
        // Two requests at once must not both find no scan pending.
        $lock = $this->locks->createLock(self::REQUEST_LOCK);
        $lock->acquire(true);
        try {
            $this->failAbandoned();
            $pending = $this->scans->findPending();
            if ([] !== $pending) {
                throw new ScanAlreadyRequested($pending[0]);
            }

            $scan = new LibraryScan($full, $trigger);
            $this->entityManager->persist($scan);
            $this->entityManager->flush();

            return $scan;
        } finally {
            $lock->release();
        }
    }

    /**
     * Scans "running" while nobody runs them (the container stopped mid-scan), or queued for too long.
     */
    private function failAbandoned(): void
    {
        foreach ($this->scans->findPending() as $scan) {
            if (ScanStatus::Running === $scan->getStatus()) {
                $probe = $this->locks->createLock(self::RUN_LOCK);
                if ($probe->acquire(false)) {
                    $probe->release();
                    $scan->fail('The scan was interrupted (the server or its worker stopped). Scan again.');
                }
            } elseif ($scan->getRequestedAt()->getTimestamp() < time() - self::QUEUED_AT_MOST_SECONDS) {
                $scan->fail('The scan never started: the library worker did not pick it up. Scan again.');
            }
        }
        $this->entityManager->flush();
    }
}
