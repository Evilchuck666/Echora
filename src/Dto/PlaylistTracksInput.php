<?php

namespace App\Dto;

use App\Entity\Playlist;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body of POST /api/playlists/{id}/items: the tracks to add at the end, in this order, duplicates included.
 */
final readonly class PlaylistTracksInput
{
    /**
     * @param list<string> $tracks
     */
    public function __construct(
        #[Assert\Count(min: 1, max: Playlist::MAX_ITEMS, minMessage: 'Send at least one track.', maxMessage: 'A playlist has at most {{ limit }} items.')]
        #[Assert\All([new Assert\Regex(TrackReference::PATTERN, message: TrackReference::MESSAGE)])]
        public array $tracks,
    ) {
    }

    /**
     * @return list<string> Identifiers (RFC 4122) of the tracks, in the order given, duplicates included
     */
    public function trackIds(): array
    {
        return TrackReference::ids($this->tracks);
    }
}
