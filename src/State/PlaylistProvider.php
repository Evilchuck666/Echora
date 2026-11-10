<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Playlist;
use App\Playlists\PlaylistOrder;
use App\Playlists\PlaylistSummaries;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GET /api/playlists and /api/playlists/{id}: the playlists from Doctrine (the user's only, see
 * App\Doctrine\OwnedByCurrentUserExtension), with their number of items, length and covers filled for the whole page
 * at once (App\Playlists\PlaylistSummaries), and their position in the user's order.
 *
 * @implements ProviderInterface<Playlist>
 */
final readonly class PlaylistProvider implements ProviderInterface
{
    /**
     * @param ProviderInterface<Playlist> $collectionProvider
     * @param ProviderInterface<Playlist> $itemProvider
     */
    public function __construct(
        #[Autowire(service: CollectionProvider::class)]
        private ProviderInterface $collectionProvider,
        #[Autowire(service: ItemProvider::class)]
        private ProviderInterface $itemProvider,
        private PlaylistSummaries $summaries,
        private PlaylistOrder $order,
        private Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $playlists = $this->collectionProvider->provide($operation, $uriVariables, $context);
            if (is_iterable($playlists)) {
                $page = $this->onlyPlaylists($playlists);
                $this->summaries->fill($page);
                // In the user's order: the index in the page plus the playlists of the pages before.
                $position = $this->pagination->getOffset($operation, $context);
                foreach ($page as $playlist) {
                    $playlist->placeAt($position++);
                }
            }

            return $playlists;
        }

        $playlist = $this->itemProvider->provide($operation, $uriVariables, $context);
        if ($playlist instanceof Playlist) {
            $this->summaries->fill([$playlist]);
            $playlist->placeAt($this->order->positionOf($playlist));
        }

        return $playlist;
    }

    /**
     * @param iterable<mixed> $items
     *
     * @return list<Playlist>
     */
    private function onlyPlaylists(iterable $items): array
    {
        $playlists = [];
        foreach ($items as $item) {
            if ($item instanceof Playlist) {
                $playlists[] = $item;
            }
        }

        return $playlists;
    }
}
