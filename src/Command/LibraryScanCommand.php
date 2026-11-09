<?php

namespace App\Command;

use App\Library\LibraryScanner;
use App\Library\LibraryUnavailable;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:library:scan',
    description: 'Synchronises the database with the FLAC and MP3 files of the music library',
)]
final readonly class LibraryScanCommand
{
    public function __construct(private LibraryScanner $scanner)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder to scan instead of MUSIC_LIBRARY_PATH')]
        ?string $directory = null,
    ): int {
        try {
            $report = $this->scanner->scan($directory);
        } catch (LibraryUnavailable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Added' => $report->added],
            ['Updated' => $report->updated],
            ['Unchanged' => $report->unchanged],
            ['Removed' => $report->removed],
            ['Failed' => \count($report->failed)],
        );

        if ([] !== $report->failed) {
            $io->warning('Some files could not be read and were skipped:');
            $io->listing(array_map(
                static fn (string $path, string $error): string => \sprintf('%s: %s', $path, $error),
                array_keys($report->failed),
                $report->failed,
            ));
        }

        $io->success('The library is up to date.');

        return Command::SUCCESS;
    }
}
