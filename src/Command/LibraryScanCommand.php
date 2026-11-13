<?php

namespace App\Command;

use App\Entity\LibraryScan;
use App\Library\LibraryScans;
use App\Library\ScanAlreadyRequested;
use App\Library\ScanStatus;
use App\Library\ScanTrigger;
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
    public function __construct(private LibraryScans $scans)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Folder to scan instead of MUSIC_LIBRARY_PATH')]
        ?string $directory = null,
        #[Option('Read every file again, even unchanged ones (e.g. after an update of Echora that reads more tags)')]
        bool $full = false,
        #[Option('Don\'t look up album covers and artist images on TheAudioDB (the album and artist folders are still searched)')]
        bool $offline = false,
        #[Option('Run by the nightly job (frankenphp/crontab): a scan already queued or running is not an error')]
        bool $scheduled = false,
    ): int {
        try {
            $scan = $this->scans->runNow($scheduled ? ScanTrigger::Schedule : ScanTrigger::Console, $full, !$offline, $directory);
        } catch (ScanAlreadyRequested $e) {
            $io->warning($e->getMessage());

            return $scheduled ? Command::SUCCESS : Command::FAILURE;
        }

        if (ScanStatus::Finished !== $scan->getStatus()) {
            $io->error($scan->getError() ?? 'The scan did not finish.');

            return Command::FAILURE;
        }

        $this->report($io, $scan);
        $io->success('The library is up to date.');

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, LibraryScan $scan): void
    {
        $io->definitionList(
            ['Added' => $scan->getAdded()],
            ['Moved or renamed' => $scan->getMoved()],
            ['Updated' => $scan->getUpdated()],
            ['Unchanged' => $scan->getUnchanged()],
            ['Removed' => $scan->getRemoved()],
            ['Covers updated' => $scan->getCoversUpdated()],
            ['Covers downloaded' => $scan->getCoversDownloaded()],
            ['Artist images updated' => $scan->getArtistImagesUpdated()],
            ['Artist images downloaded' => $scan->getArtistImagesDownloaded()],
            ['Failed' => $scan->getFailed()],
        );

        $failures = $scan->getFailures() ?? [];
        if ([] !== $failures) {
            $io->warning('Some files could not be read and were skipped:');
            $io->listing(array_map(
                static fn (string $path, string $error): string => \sprintf('%s: %s', $path, $error),
                array_keys($failures),
                $failures,
            ));
        }

        if (null !== $scan->getTheAudioDbFailure()) {
            $io->warning(\sprintf('%s The remaining album covers and artist images will be looked up on the next scan.', $scan->getTheAudioDbFailure()));
        }
    }
}
