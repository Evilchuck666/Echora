<?php

namespace App\Tests\Playlists;

use App\Doctrine\SqlValue;
use App\Entity\Playlist;
use App\Entity\User;
use App\Library\LibraryScanner;
use App\Ordering\SortKeys;
use App\Playlists\PlaylistEditor;
use App\Playlists\PlaylistOrder;
use App\Repository\TrackRepository;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\Process;

/**
 * Two browser tabs (or devices) changing the same playlist at once: each change waits for the previous one, instead of
 * reading the same order and writing clashing keys. Real processes and committed data, so no rollback: everything is
 * deleted afterwards.
 */
#[SkipDatabaseRollback]
final class PlaylistConcurrencyTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::getContainer()->get(LibraryScanner::class)->scan(download: false);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('TRUNCATE playlist_item, playlist, favourite, track, album, artist, app_user CASCADE');
        parent::tearDown();
    }

    public function testChangesToTheSamePlaylistAtOnceWaitForEachOtherAndAllSucceed(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('two-tabs');
        $playlist = new Playlist($user, 'Shared by two tabs', SortKeys::STEP);
        $entityManager->persist($user);
        $entityManager->persist($playlist);
        $entityManager->flush();
        $playlistId = $playlist->getId()->toRfc4122();
        [$first, $one, $two, $three] = self::getContainer()->get(TrackRepository::class)->findAllIds();
        self::getContainer()->get(PlaylistEditor::class)->add($user, $playlistId, [$first, $first]);
        $movedItem = SqlValue::string($this->connection->fetchOne('SELECT id FROM playlist_item WHERE playlist_id = ? ORDER BY sort_key LIMIT 1', [$playlistId]));

        // Another tab holds the playlist while four changes arrive.
        /** @phpstan-ignore argument.type (the parameters of the test connection itself) */
        $otherTab = DriverManager::getConnection($this->connection->getParams(), $this->connection->getConfiguration());
        $otherTab->beginTransaction();
        $otherTab->fetchOne('SELECT 1 FROM playlist WHERE id = ? FOR UPDATE', [$playlistId]);

        $processes = [];
        foreach ([$one, $two, $three] as $track) {
            $processes[] = $this->start(['add', 'two-tabs', $playlistId, $track, $track]);
        }
        $processes[] = $this->start(['move', 'two-tabs', $movedItem, '99']);
        try {
            $this->waitUntilWaitingForALock(\count($processes));
        } finally {
            // Always let go, or a failure would leave the lock held and tearDown() waiting for it forever.
            $otherTab->commit();
            $otherTab->close();
        }

        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }

        $order = SqlValue::strings($this->connection->fetchFirstColumn(
            'SELECT track_id FROM playlist_item WHERE playlist_id = ? ORDER BY sort_key',
            [$playlistId],
        ));
        self::assertCount(8, $order, 'Every change was made.');
        foreach ([$one, $two, $three] as $track) {
            $positions = array_keys($order, $track, true);
            self::assertCount(2, $positions);
            self::assertSame($positions[0] + 1, $positions[1], 'The tracks of one request stay together.');
        }
        // Moved to the end when its turn came: only whole additions, made after it, may follow it.
        $after = SqlValue::int($this->connection->fetchOne(
            'SELECT COUNT(*) FROM playlist_item WHERE playlist_id = ? AND sort_key > (SELECT sort_key FROM playlist_item WHERE id = ?)',
            [$playlistId, $movedItem],
        ));
        self::assertSame(0, $after % 2);
        self::assertSame($first, $order[0], 'The other copy of the first track is now first.');
    }

    public function testTwoDevicesReorderingTheSamePlaylistsAtOnceWaitForEachOther(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('two-devices');
        $entityManager->persist($user);
        $entityManager->flush();
        $order = self::getContainer()->get(PlaylistOrder::class);
        $existing = array_map(static fn (string $name): string => $order->create($user, $name)->getId()->toRfc4122(), ['One', 'Two', 'Three']);
        $userId = $user->getId()->toRfc4122();

        // Another device holds the user's order while four changes arrive.
        /** @phpstan-ignore argument.type (the parameters of the test connection itself) */
        $otherDevice = DriverManager::getConnection($this->connection->getParams(), $this->connection->getConfiguration());
        $otherDevice->beginTransaction();
        $otherDevice->fetchOne('SELECT 1 FROM app_user WHERE id = ? FOR NO KEY UPDATE', [$userId]);

        $processes = [
            $this->start(['create-playlist', 'two-devices', 'From the phone']),
            $this->start(['create-playlist', 'two-devices', 'From the tablet']),
            $this->start(['move-playlist', 'two-devices', $existing[0], '0']),
            $this->start(['move-playlist', 'two-devices', $existing[2], '99']),
        ];
        try {
            $this->waitUntilWaitingForALock(\count($processes));
        } finally {
            // Always let go, or a failure would leave the lock held and tearDown() waiting for it forever.
            $otherDevice->commit();
            $otherDevice->close();
        }

        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        }

        $names = SqlValue::strings($this->connection->fetchFirstColumn('SELECT name FROM playlist WHERE owner_id = ? ORDER BY sort_key', [$userId]));
        self::assertCount(5, $names, 'Every change was made.');
        self::assertEqualsCanonicalizing(['One', 'Two', 'Three', 'From the phone', 'From the tablet'], $names);
    }

    /**
     * @param list<string> $arguments
     */
    private function start(array $arguments): Process
    {
        $process = new Process([\PHP_BINARY, __DIR__.'/change-playlist.php', ...$arguments], \dirname(__DIR__, 2), ['APP_ENV' => 'test']);
        $process->setTimeout(60);
        $process->start();

        return $process;
    }

    private function waitUntilWaitingForALock(int $processes): void
    {
        $deadline = microtime(true) + 30;
        do {
            $waiting = SqlValue::int($this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE datname = current_database() AND wait_event_type = 'Lock'"));
            if ($waiting >= $processes) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail(\sprintf('Only %d of the %d changes waited for the lock.', $waiting, $processes));
    }
}
