<?php

namespace App\Database;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Backups of the database (docs/decisions/0018): the accounts, playlists, favourites and history, which no scan can
 * bring back. pg_dump in its custom format (compressed, restored with pg_restore), into the backups folder (the
 * backups volume, or BACKUP_DIR, in production), one file per backup.
 *
 * pg_dump and pg_restore come with the image (postgresql-client), and get the connection of Doctrine's default
 * connection through libpq's environment variables, never on their command line.
 */
final readonly class DatabaseBackups
{
    /** echora-20261113-023000.dump */
    private const string FILE_PATTERN = '/^echora-\d{8}-\d{6}\.dump$/';

    /** A dump of a big database can take a while; this only stops one that hangs. */
    private const int TIMEOUT_SECONDS = 3600;

    public function __construct(
        private Connection $connection,
        #[Autowire('%app.backup_dir%')]
        private string $directory,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * Dumps the database into a new file.
     *
     * @return string Absolute path of the backup
     *
     * @throws BackupFailed
     */
    public function create(): string
    {
        $this->filesystem->mkdir($this->directory);
        $file = \sprintf('%s/echora-%s.dump', $this->directory, (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Ymd-His'));
        // Written next to its final name, then renamed: a backup is never half a file.
        $partial = $file.'.part';

        $this->run(['pg_dump', '--format=custom', '--no-owner', '--no-privileges', '--file='.$partial], 'pg_dump', $partial);
        $this->filesystem->rename($partial, $file, true);

        return $file;
    }

    /**
     * Restores a backup: the database becomes what it was then, all at once or not at all (one transaction).
     *
     * @param string      $file     Path of the backup
     * @param string|null $database Another database to restore into (tests); the application's one by default
     *
     * @throws BackupFailed
     */
    public function restore(string $file, ?string $database = null): void
    {
        if (!is_file($file)) {
            throw new BackupFailed(\sprintf('There is no backup "%s".', $file));
        }

        // --clean drops what the backup holds before creating it again; --single-transaction undoes it all on an error.
        // Unlike pg_dump, pg_restore needs --dbname (without it, it would only print the SQL): the name alone, the rest
        // of the connection comes from the environment.
        $database ??= $this->parameter('dbname', '');
        $this->run(['pg_restore', '--clean', '--if-exists', '--no-owner', '--no-privileges', '--single-transaction', '--dbname='.$database, $file], 'pg_restore', database: $database);
    }

    /**
     * @return list<string> Absolute paths of the backups, the latest first
     */
    public function list(): array
    {
        $files = array_filter(glob($this->directory.'/echora-*.dump') ?: [], static fn (string $file): bool => 1 === preg_match(self::FILE_PATTERN, basename($file)));
        rsort($files);

        return $files;
    }

    /**
     * Deletes the backups older than the given number of days, but never the latest one, and partial files left by an
     * interrupted dump.
     *
     * @return int Number of files deleted
     */
    public function prune(int $keepDays): int
    {
        $limit = time() - $keepDays * 86400;
        $deleted = 0;

        foreach (\array_slice($this->list(), 1) as $file) {
            if ((int) filemtime($file) < $limit) {
                $this->filesystem->remove($file);
                ++$deleted;
            }
        }
        foreach (glob($this->directory.'/echora-*.dump.part') ?: [] as $partial) {
            if ((int) filemtime($partial) < time() - self::TIMEOUT_SECONDS) {
                $this->filesystem->remove($partial);
                ++$deleted;
            }
        }

        return $deleted;
    }

    /**
     * A parameter of Doctrine's connection, as a string for libpq's environment variables.
     */
    private function parameter(string $name, string $default): string
    {
        $value = $this->connection->getParams()[$name] ?? null;

        return \is_string($value) || \is_int($value) ? (string) $value : $default;
    }

    /**
     * @param list<string> $command
     *
     * @throws BackupFailed
     */
    private function run(array $command, string $tool, ?string $partial = null, ?string $database = null): void
    {
        $process = new Process($command, env: [
            'PGHOST' => $this->parameter('host', 'localhost'),
            'PGPORT' => $this->parameter('port', '5432'),
            'PGUSER' => $this->parameter('user', ''),
            'PGPASSWORD' => $this->parameter('password', ''),
            'PGDATABASE' => $database ?? $this->parameter('dbname', ''),
        ]);
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            if (null !== $partial) {
                $this->filesystem->remove($partial);
            }

            throw new BackupFailed(\sprintf('%s failed: %s', $tool, trim($process->getErrorOutput()) ?: 'exit code '.$process->getExitCode()));
        }
    }
}
