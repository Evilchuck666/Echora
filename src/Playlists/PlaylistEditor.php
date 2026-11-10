<?php

namespace App\Playlists;

use App\Doctrine\SqlValue;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use App\Entity\User;
use App\Ordering\SortKeys;
use App\Repository\PlaylistItemRepository;
use App\Repository\PlaylistRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Changes the items of a playlist: adds tracks at the end, moves an item, removes one.
 *
 * Each change runs in a transaction that first locks the playlist's row (SELECT ... FOR UPDATE): two requests on the
 * same playlist (two browser tabs, two devices) wait for each other instead of reading the same order and writing
 * clashing keys. The order is that of the items' sort keys (App\Ordering\SortKeys, scoped by playlist): positions
 * (indexes from 0) are derived from it, so they stay consecutive whatever is removed, by the API or by a scan.
 *
 * All methods throw PlaylistNotFound when the playlist or item doesn't exist or isn't the user's.
 */
final readonly class PlaylistEditor
{
    private Connection $connection;
    private SortKeys $sortKeys;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaylistRepository $playlists,
        private PlaylistItemRepository $items,
    ) {
        $this->connection = $entityManager->getConnection();
        $this->sortKeys = new SortKeys($this->connection, 'playlist_item', 'playlist_id');
    }

    /**
     * Adds the tracks at the end of the playlist, in this order, all or none.
     *
     * @param list<string> $trackIds Identifiers (RFC 4122); the same track may come several times
     *
     * @throws PlaylistNotFound
     * @throws UnknownTracks
     * @throws PlaylistFull
     */
    public function add(User $user, string $playlistId, array $trackIds): Playlist
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $playlistId, $trackIds): Playlist {
            $playlist = $this->lock($user, $playlistId);
            $id = $playlist->getId()->toRfc4122();

            $unknown = array_values(array_diff(array_unique($trackIds), $this->existingTrackIds($trackIds)));
            if ([] !== $unknown) {
                throw new UnknownTracks($unknown);
            }

            $count = $this->sortKeys->count($id);
            if ($count + \count($trackIds) > Playlist::MAX_ITEMS) {
                throw new PlaylistFull($count, \count($trackIds));
            }

            $key = $this->sortKeys->last($id) - SortKeys::STEP;
            $addedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach (array_chunk($trackIds, 500) as $chunk) {
                $values = [];
                $parameters = [];
                foreach ($chunk as $trackId) {
                    $key += SortKeys::STEP;
                    $values[] = '(?, ?, ?, ?, ?)';
                    array_push($parameters, Uuid::v7()->toRfc4122(), $id, $trackId, $key, $addedAt);
                }
                $this->connection->executeStatement('INSERT INTO playlist_item (id, playlist_id, track_id, sort_key, added_at) VALUES '.implode(', ', $values), $parameters);
            }

            $playlist->touch();

            return $playlist;
        });
    }

    /**
     * Moves the item to the given index (from 0) in the playlist's current order; the items in between shift by one.
     * An index beyond the last item moves it to the end.
     *
     * @throws PlaylistNotFound
     */
    public function move(User $user, string $itemId, int $position): PlaylistItem
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $itemId, $position): PlaylistItem {
            [$playlist, $item] = $this->lockItem($user, $itemId);
            $before = $this->sortKeys->positionOf($playlist->getId()->toRfc4122(), $item->getId()->toRfc4122());
            $target = $this->sortKeys->move($playlist->getId()->toRfc4122(), $item->getId()->toRfc4122(), $position);
            if ($target !== $before) {
                $playlist->touch();
            }

            $this->entityManager->refresh($item);
            $item->setPosition($target);

            return $item;
        });
    }

    /**
     * Removes the item (that appearance of the track only).
     *
     * @throws PlaylistNotFound
     */
    public function remove(User $user, string $itemId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $itemId): void {
            [$playlist, $item] = $this->lockItem($user, $itemId);
            $this->connection->executeStatement('DELETE FROM playlist_item WHERE id = ?', [$item->getId()->toRfc4122()]);
            $this->entityManager->detach($item);
            $playlist->touch();
        });
    }

    /**
     * Locks the playlist's row until the end of the transaction.
     *
     * @throws PlaylistNotFound
     */
    private function lock(User $user, string $playlistId): Playlist
    {
        $playlist = $this->playlists->findOwned($user, $playlistId) ?? throw new PlaylistNotFound();
        // Also tells whether a request that held the lock deleted the playlist meanwhile.
        if (false === $this->connection->fetchOne('SELECT 1 FROM playlist WHERE id = ? FOR UPDATE', [$playlist->getId()->toRfc4122()])) {
            throw new PlaylistNotFound();
        }

        return $playlist;
    }

    /**
     * Locks the item's playlist, then makes sure the item is still there (a request holding the lock may have removed
     * it meanwhile).
     *
     * @return array{Playlist, PlaylistItem}
     *
     * @throws PlaylistNotFound
     */
    private function lockItem(User $user, string $itemId): array
    {
        $item = $this->items->findOwned($user, $itemId) ?? throw new PlaylistNotFound();
        $playlist = $this->lock($user, $item->getPlaylist()->getId()->toRfc4122());
        if (false === $this->connection->fetchOne('SELECT 1 FROM playlist_item WHERE id = ?', [$item->getId()->toRfc4122()])) {
            throw new PlaylistNotFound();
        }

        return [$playlist, $item];
    }

    /**
     * @param list<string> $trackIds
     *
     * @return list<string>
     */
    private function existingTrackIds(array $trackIds): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique($trackIds)), 1000) as $chunk) {
            array_push($existing, ...SqlValue::strings($this->connection->fetchFirstColumn('SELECT id FROM track WHERE id IN (?)', [$chunk], [ArrayParameterType::STRING])));
        }

        return $existing;
    }
}
