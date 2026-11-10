<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\State\CollectionProvider;
use ApiPlatform\Doctrine\Orm\State\ItemProvider;
use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Playlist;
use App\Playlists\PlaylistSummaries;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * GET /api/playlists and /api/playlists/{id}: the playlists from Doctrine (the user's only, see
 * App\Doctrine\OwnedByCurrentUserExtension), with their number of items, length and covers filled for the whole page
 * at once (App\Playlists\PlaylistSummaries).
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
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            $playlists = $this->collectionProvider->provide($operation, $uriVariables, $context);
            if (is_iterable($playlists)) {
                $this->summaries->fill($this->onlyPlaylists($playlists));
            }

            return $playlists;
        }

        $playlist = $this->itemProvider->provide($operation, $uriVariables, $context);
        if ($playlist instanceof Playlist) {
            $this->summaries->fill([$playlist]);
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
