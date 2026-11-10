<?php

namespace App\Playlists;

use App\Entity\Playlist;

/**
 * Adding the tracks would take the playlist beyond Playlist::MAX_ITEMS: none was added.
 */
final class PlaylistFull extends \DomainException
{
    public function __construct(int $items, int $adding)
    {
        parent::__construct(\sprintf('A playlist has at most %d items: this one has %d, %d more would be too many. No track was added.', Playlist::MAX_ITEMS, $items, $adding));
    }
}
