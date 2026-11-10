<?php

namespace App\Playlists;

/**
 * Some of the tracks to add to a playlist don't exist: none was added.
 */
final class UnknownTracks extends \DomainException
{
    /**
     * @param list<string> $trackIds
     */
    public function __construct(public readonly array $trackIds)
    {
        parent::__construct(\sprintf('Unknown track%s: %s. No track was added.', 1 === \count($trackIds) ? '' : 's', implode(', ', $trackIds)));
    }
}
