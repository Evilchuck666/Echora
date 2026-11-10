<?php

namespace App\Playlists;

/**
 * The playlist or item doesn't exist, or belongs to someone else (which is the same for the user: 404).
 */
final class PlaylistNotFound extends \DomainException
{
    public function __construct()
    {
        parent::__construct('No playlist or playlist item with this id.');
    }
}
