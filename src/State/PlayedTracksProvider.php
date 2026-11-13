<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlayedTrack;
use App\Entity\User;
use App\Repository\PlayRepository;
use App\Repository\TrackRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/me/recent-tracks and /api/me/top-tracks: a page of the user's played tracks in three queries (how many,
 * the page's counts, then its tracks with their albums and artists), never one per track.
 *
 * @implements ProviderInterface<PlayedTrack>
 */
final readonly class PlayedTracksProvider implements ProviderInterface
{
    public const string RECENT = 'recent_tracks';
    public const string TOP = 'top_tracks';
    public const array PERIODS = ['week', 'month', 'year', 'all'];

    private const array SINCE = ['week' => '-7 days', 'month' => '-30 days', 'year' => '-1 year'];

    public function __construct(
        private Security $security,
        private PlayRepository $plays,
        private TrackRepository $tracks,
        private Pagination $pagination,
    ) {
    }

    /**
     * @return TraversablePaginator<PlayedTrack>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $top = self::TOP === $operation->getName();
        $period = $top && \is_array($context['filters'] ?? null) ? ($context['filters']['period'] ?? 'all') : 'all';
        $since = \is_string($period) && isset(self::SINCE[$period]) ? new \DateTimeImmutable(self::SINCE[$period]) : null;

        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);
        $rows = $this->plays->findPlayedTracks($user, $top, $since, $offset, $limit);
        $tracks = $this->tracks->findWithAlbumAndArtist(array_column($rows, 'trackId'));

        $items = [];
        foreach ($rows as $row) {
            // A track deleted between the two queries is left out of the page.
            if (isset($tracks[$row['trackId']])) {
                $items[] = new PlayedTrack($tracks[$row['trackId']], $row['playCount'], $row['lastPlayedAt']);
            }
        }

        return new TraversablePaginator(new \ArrayIterator($items), intdiv($offset, max(1, $limit)) + 1, $limit, $this->plays->countPlayedTracks($user, $since));
    }
}
