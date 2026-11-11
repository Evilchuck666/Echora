<?php

namespace App\Tests\Migrations;

use App\Doctrine\SqlValue;
use App\Entity\RefreshToken;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * When sessions started naming accounts by id instead of username (Version20261111130044), the refresh tokens already
 * issued were rewritten, so nobody had to sign in again; existing accounts were named like their username.
 */
final class UserAccountsMigrationTest extends KernelTestCase
{
    private const string MIGRATION = 'DoctrineMigrations\Version20261111130044';

    public function testExistingSessionsAndNamesSurviveTheMigration(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User('listener', 'listener@example.com', 'Lisa');
        $entityManager->persist($user);
        $entityManager->persist(RefreshToken::createForUserWithTtl(bin2hex(random_bytes(16)), $user, 3600));
        $entityManager->flush();
        $id = $user->getId()->toRfc4122();

        $this->migrate('--down');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::assertSame(['listener'], SqlValue::strings($connection->fetchFirstColumn('SELECT username FROM refresh_tokens')), 'Before: the username.');

        // A migration runs once per process: a new kernel loads it again (DAMA keeps the same transaction).
        self::ensureKernelShutdown();
        self::bootKernel();
        $this->migrate('--up');
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');

        self::assertSame([$id], SqlValue::strings($connection->fetchFirstColumn('SELECT username FROM refresh_tokens')), 'After: the id.');
        self::assertSame('listener', $connection->fetchOne('SELECT name FROM app_user WHERE id = ?', [$id]), 'Named like its username.');
    }

    private function migrate(string $direction): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester((new Application($kernel))->find('doctrine:migrations:execute'));
        $tester->execute(['versions' => [self::MIGRATION], $direction => true], ['interactive' => false]);
        $tester->assertCommandIsSuccessful($tester->getDisplay());
    }
}
