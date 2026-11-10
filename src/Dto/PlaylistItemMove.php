<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of PATCH /api/playlist_items/{id}: where to move the item.
 */
final readonly class PlaylistItemMove
{
    public function __construct(
        /** Index in the current order, from 0; beyond the last item means the end. */
        #[Assert\PositiveOrZero(message: 'The position is an index from 0.')]
        public int $position,
    ) {
    }
}
