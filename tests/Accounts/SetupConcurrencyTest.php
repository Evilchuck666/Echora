<?php

namespace App\Tests\Accounts;

use App\Doctrine\SqlValue;
use DAMA\DoctrineTestBundle\PHPUnit\SkipDatabaseRollback;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Process;

/**
 * Two people opening a new server at the same moment: only one becomes its administrator. Real processes and committed
 * data, so no rollback: the accounts are deleted afterwards.
 */
#[SkipDatabaseRollback]
final class SetupConcurrencyTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement('DELETE FROM refresh_tokens');
        $this->connection->executeStatement('TRUNCATE app_user CASCADE');
        parent::tearDown();
    }

    public function testTwoFirstAccountsAtOnceMakeOneAdministrator(): void
    {
        // Hold the lock App\Security\UserAccounts takes, while both requests arrive.
        $lock = self::getContainer()->get(LockFactory::class)->createLock('user-accounts', 60);
        self::assertTrue($lock->acquire());

        $processes = [];
        try {
            foreach (['ana@example.com', 'bob@example.com'] as $email) {
                $process = new Process([\PHP_BINARY, __DIR__.'/post-setup.php', $email], \dirname(__DIR__, 2), ['APP_ENV' => 'test']);
                $process->setTimeout(60);
                $process->start();
                $processes[] = $process;
            }
            $this->waitUntilWaitingForTheLock(\count($processes));
        } finally {
            // Always let go, or a failure would leave both requests waiting.
            $lock->release();
        }

        $exitCodes = [];
        foreach ($processes as $process) {
            $process->wait();
            $exitCodes[] = $process->getExitCode();
        }
        sort($exitCodes);

        self::assertSame([0, 3], $exitCodes, 'One account created (201), the other refused (409).');
        self::assertSame(1, SqlValue::int($this->connection->fetchOne('SELECT COUNT(*) FROM app_user')));
    }

    private function waitUntilWaitingForTheLock(int $processes): void
    {
        $deadline = microtime(true) + 30;
        do {
            // Advisory locks live in the database of LOCK_DSN, which may not be the test database.
            $waiting = SqlValue::int($this->connection->fetchOne("SELECT COUNT(*) FROM pg_stat_activity WHERE wait_event_type = 'Lock' AND wait_event = 'advisory'"));
            if ($waiting >= $processes) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        self::fail(\sprintf('Only %d of the %d requests waited for the lock.', $waiting, $processes));
    }
}
