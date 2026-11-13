<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/tracks/{id}/plays, optional: when the track was played, for plays sent later (offline).
 */
final readonly class PlayInput
{
    public function __construct(
        #[Assert\LessThanOrEqual('+5 minutes', message: 'A play cannot be in the future.')]
        #[Assert\GreaterThanOrEqual('-1 year', message: 'Plays older than a year are not recorded.')]
        public ?\DateTimeImmutable $playedAt = null,
    ) {
    }
}
