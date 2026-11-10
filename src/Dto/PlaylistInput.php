<?php

namespace App\Dto;

use App\Validator\PlaylistName;

/**
 * Body of POST /api/playlists.
 */
final readonly class PlaylistInput
{
    public function __construct(
        #[PlaylistName]
        public string $name,
    ) {
    }
}
