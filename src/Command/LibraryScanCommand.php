<?php

namespace App\Command;

use App\Library\LibraryScanner;
use App\Library\LibraryUnavailable;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
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
        #[Option('Read every file again, even unchanged ones (e.g. after an update of Echora that reads more tags)')]
        bool $full = false,
        #[Option('Don\'t look up artist images on TheAudioDB (the artist folders are still searched)')]
        bool $offline = false,
    ): int {
        try {
            $report = $this->scanner->scan($directory, $full, !$offline);
        } catch (LibraryUnavailable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->definitionList(
            ['Added' => $report->added],
            ['Updated' => $report->updated],
            ['Unchanged' => $report->unchanged],
            ['Removed' => $report->removed],
            ['Covers updated' => $report->coversUpdated],
            ['Artist images updated' => $report->artistImagesUpdated],
            ['Artist images downloaded' => $report->artistImagesDownloaded],
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

        if (null !== $report->artistImageLookupFailure) {
            $io->warning(\sprintf('%s The remaining artist images will be looked up on the next scan.', $report->artistImageLookupFailure));
        }

        $io->success('The library is up to date.');

        return Command::SUCCESS;
    }
}
