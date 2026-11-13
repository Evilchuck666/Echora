<?php

namespace App\Command;

use App\Database\BackupFailed;
use App\Database\DatabaseBackups;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:database:backup',
    description: 'Backs the database up (accounts, playlists, favourites, history) and deletes the backups older than a few days',
)]
final readonly class DatabaseBackupCommand
{
    public function __construct(
        private DatabaseBackups $backups,
        #[Autowire('%env(int:BACKUP_KEEP_DAYS)%')]
        private int $defaultKeepDays,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Delete the backups older than this many days (BACKUP_KEEP_DAYS by default); the latest one is always kept')]
        ?int $keepDays = null,
    ): int {
        $keepDays ??= $this->defaultKeepDays;
        if ($keepDays < 1) {
            $io->error('Keep the backups for at least a day.');

            return Command::INVALID;
        }

        try {
            $file = $this->backups->create();
        } catch (BackupFailed $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $deleted = $this->backups->prune($keepDays);
        $io->success(\sprintf('Backed up to %s (%s KB). Deleted %d backup%s older than %d day%s.', $file, number_format((int) filesize($file) / 1024, 0), $deleted, 1 === $deleted ? '' : 's', $keepDays, 1 === $keepDays ? '' : 's'));

        return Command::SUCCESS;
    }
}
