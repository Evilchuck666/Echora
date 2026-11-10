<?php

namespace App\Tests\Migrations;

use App\Doctrine\SqlValue;
use App\Entity\User;
use App\Ordering\SortKeys;
use App\Playlists\PlaylistOrder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * When playlists got an order of their own (Version20261110205531), existing ones kept the order they were listed in
 * until then: last changed first, then by id, for each user.
 */
final class PlaylistOrderMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261110205531';

    public function testExistingPlaylistsKeepTheOrderTheyHadByLastChange(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $order = self::getContainer()->get(PlaylistOrder::class);
        $alice = new User('alice');
        $bob = new User('bob');
        $entityManager->persist($alice);
        $entityManager->persist($bob);
        $entityManager->flush();
        $playlists = [];
        foreach (['Old', 'Recent', 'Same time A', 'Same time B'] as $name) {
            $playlists[$name] = $order->create($alice, $name)->getId()->toRfc4122();
        }
        $bobs = $order->create($bob, 'Bob\'s')->getId()->toRfc4122();

        $this->migrate('--down');
        foreach (['Old' => '2026-01-01', 'Recent' => '2026-03-01', 'Same time A' => '2026-02-01', 'Same time B' => '2026-02-01'] as $name => $date) {
            $connection->executeStatement('UPDATE playlist SET updated_at = ? WHERE id = ?', [$date, $playlists[$name]]);
        }
        // A migration runs once per process: a new kernel loads it again (DAMA keeps the same transaction).
        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->migrate('--up');

        // Same date: the higher id (created later) first, as GET /api/playlists listed them.
        $expected = [$playlists['Recent'], $playlists['Same time B'], $playlists['Same time A'], $playlists['Old']];
        self::assertSame($expected, $this->ids($connection, $alice));
        self::assertSame([$bobs], $this->ids($connection, $bob), 'Each user has their own order.');
        self::assertSame(
            [SortKeys::STEP, 2 * SortKeys::STEP, 3 * SortKeys::STEP, 4 * SortKeys::STEP],
            SqlValue::ints($connection->fetchFirstColumn('SELECT sort_key FROM playlist WHERE owner_id = ? ORDER BY sort_key', [$alice->getId()->toRfc4122()])),
        );
    }

    private function migrate(string $direction): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::MIGRATION], $direction => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }

    /**
     * @return list<string>
     */
    private function ids(Connection $connection, User $owner): array
    {
        return SqlValue::strings($connection->fetchFirstColumn('SELECT id FROM playlist WHERE owner_id = ? ORDER BY sort_key', [$owner->getId()->toRfc4122()]));
    }
}
