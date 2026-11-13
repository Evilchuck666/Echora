<?php

namespace App\Tests\Command;

use App\Database\BackupFailed;
use App\Database\DatabaseBackups;
use App\Doctrine\SqlValue;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Database backups (docs/decisions/0018): app:database:backup dumps the database with pg_dump, app:database:restore
 * puts one back with pg_restore.
 */
final class DatabaseBackupTest extends KernelTestCase
{
    private const string SCRATCH_DATABASE = 'echora_restore_test';

    private DatabaseBackups $backups;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->backups = self::getContainer()->get(DatabaseBackups::class);
        (new Filesystem())->remove($this->backups->directory());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->backups->directory());
        parent::tearDown();
    }

    public function testABackupIsAPgDumpArchiveOfTheDatabase(): void
    {
        $tester = $this->command('app:database:backup');

        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $backups = $this->backups->list();
        self::assertCount(1, $backups);
        self::assertMatchesRegularExpression('/^echora-\d{8}-\d{6}\.dump$/', basename($backups[0]));
        self::assertSame('PGDMP', file_get_contents($backups[0], length: 5), 'The custom format of pg_dump.');
        self::assertStringContainsString(basename($backups[0]), $tester->getDisplay());
    }

    public function testOldBackupsAreDeletedButNeverTheLatest(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->backups->directory());
        $old = $this->backups->directory().'/echora-20260101-023000.dump';
        $older = $this->backups->directory().'/echora-20251201-023000.dump';
        $leftover = $this->backups->directory().'/echora-20251130-023000.dump.part';
        foreach ([$old, $older, $leftover] as $file) {
            $filesystem->dumpFile($file, 'old');
            touch($file, time() - 40 * 86400);
        }

        // The older backup and the partial file of an interrupted dump; the latest backup stays, however old.
        self::assertSame(2, $this->backups->prune(14));
        self::assertFileExists($old);
        self::assertFileDoesNotExist($older);
        self::assertFileDoesNotExist($leftover);

        $this->command('app:database:backup')->execute(['--keep-days' => '14']);
        self::assertFileDoesNotExist($old, 'Once a new backup exists, the old one goes.');
        self::assertCount(1, $this->backups->list());
    }

    public function testBackupsAreKeptAtLeastADay(): void
    {
        self::assertSame(Command::INVALID, $this->command('app:database:backup')->execute(['--keep-days' => '0']));
        self::assertSame([], $this->backups->list());
    }

    public function testTheRestoreCommandListsTheBackupsAndRestoresNothingWithoutAConfirmation(): void
    {
        $file = $this->backups->create();

        $list = $this->command('app:database:restore');
        $list->execute([]);
        self::assertStringContainsString(basename($file), $list->getDisplay());

        $declined = $this->command('app:database:restore');
        $declined->setInputs(['no']);
        $declined->execute(['backup' => basename($file)]);
        self::assertStringContainsString('Nothing was restored', $declined->getDisplay());

        $unattended = $this->command('app:database:restore');
        $unattended->execute(['backup' => basename($file)], ['interactive' => false]);
        self::assertStringContainsString('Nothing was restored', $unattended->getDisplay());
    }

    public function testABackupRestoresTheWholeDatabase(): void
    {
        $file = $this->backups->create();
        $server = $this->server();
        $server->executeStatement('DROP DATABASE IF EXISTS '.self::SCRATCH_DATABASE);
        $server->executeStatement('CREATE DATABASE '.self::SCRATCH_DATABASE);
        try {
            $this->backups->restore($file, self::SCRATCH_DATABASE);

            $restored = $this->connectionTo(self::SCRATCH_DATABASE);
            $tables = SqlValue::strings($restored->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename"));
            self::assertContains('track', $tables);
            self::assertContains('app_user', $tables);
            self::assertContains('play', $tables);
            self::assertGreaterThan(0, SqlValue::int($restored->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions')), 'The migrations it was at.');
            $restored->close();
        } finally {
            $server->executeStatement('DROP DATABASE IF EXISTS '.self::SCRATCH_DATABASE);
            $server->close();
        }
    }

    public function testAMissingBackupRestoresNothing(): void
    {
        $this->expectException(BackupFailed::class);

        $this->backups->restore($this->backups->directory().'/echora-20000101-000000.dump');
    }

    private function command(string $name): CommandTester
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester((new Application($kernel))->find($name));
    }

    /**
     * The database server, outside the test transaction (CREATE DATABASE can't run in one).
     */
    private function server(): Connection
    {
        return $this->connectionTo('postgres');
    }

    private function connectionTo(string $database): Connection
    {
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $parameters = $connection->getParams();
        $parameters['dbname'] = $database;
        unset($parameters['url'], $parameters['dbname_suffix']);

        /* @phpstan-ignore argument.type (the parameters of the test connection itself) */
        return DriverManager::getConnection($parameters);
    }
}
