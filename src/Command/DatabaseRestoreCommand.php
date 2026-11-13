<?php

namespace App\Command;

use App\Database\BackupFailed;
use App\Database\DatabaseBackups;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:database:restore',
    description: 'Lists the database backups, or restores one: the database becomes what it was then',
)]
final readonly class DatabaseRestoreCommand
{
    public function __construct(private DatabaseBackups $backups)
    {
    }

    public function __invoke(
        InputInterface $input,
        SymfonyStyle $io,
        #[Argument('The backup to restore (a file name from the list, or a path); without it, the backups are listed')]
        ?string $backup = null,
    ): int {
        if (null === $backup) {
            $backups = $this->backups->list();
            if ([] === $backups) {
                $io->note(\sprintf('There are no backups in %s yet. Make one with app:database:backup.', $this->backups->directory()));

                return Command::SUCCESS;
            }
            $io->table(['Backup', 'Size'], array_map(static fn (string $file): array => [basename($file), number_format((int) filesize($file) / 1024, 0).' KB'], $backups));
            $io->text('Restore one with: app:database:restore <backup>');

            return Command::SUCCESS;
        }

        $file = str_contains($backup, '/') ? $backup : $this->backups->directory().'/'.$backup;
        $io->warning([
            \sprintf('Everything in the database will be replaced by %s: accounts, playlists, favourites, history and library made since are lost.', basename($file)),
            'Make a backup first (app:database:backup) if unsure. Scan the library afterwards to bring the tracks up to date.',
        ]);
        if (!$input->isInteractive() || !$io->confirm('Restore it?', false)) {
            $io->note('Nothing was restored. (This command only restores after a confirmation in a terminal.)');

            return Command::SUCCESS;
        }

        try {
            $this->backups->restore($file);
        } catch (BackupFailed $e) {
            $io->error([$e->getMessage(), 'The database was left as it was.']);

            return Command::FAILURE;
        }

        $io->success(\sprintf('Restored %s. Restart the php service (migrations run on start), then scan the library.', basename($file)));

        return Command::SUCCESS;
    }
}
