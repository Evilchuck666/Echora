<?php

namespace App\Command;

use App\Library\OpusTranscoder;
use App\Repository\TrackRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:transcodes:prune',
    description: 'Deletes the Opus versions of tracks that nobody played for a while, or that are no longer in the library',
)]
final readonly class TranscodesPruneCommand
{
    public function __construct(
        private OpusTranscoder $transcoder,
        private TrackRepository $tracks,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('Delete the Opus versions not played for this many days')]
        int $days = 30,
    ): int {
        if ($days < 0) {
            $io->error('--days cannot be negative.');

            return Command::INVALID;
        }

        $removed = $this->transcoder->prune(new \DateTimeImmutable(\sprintf('-%d days', $days)), $this->tracks->findAllIds());

        $io->success(\sprintf('Deleted %d Opus file%s.', $removed, 1 === $removed ? '' : 's'));

        return Command::SUCCESS;
    }
}
