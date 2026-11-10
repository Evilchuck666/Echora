<?php

namespace App\Playlists;

use App\Doctrine\SqlValue;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use App\Entity\User;
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
 * clashing keys. The order is that of the items' sort keys (unique per playlist, never exposed), which leave gaps:
 * appending adds SORT_KEY_STEP to the last key, moving takes the middle of the new neighbours' keys (renumbering the
 * playlist first when they are adjacent), and removing changes no other key. Positions (indexes from 0) are derived
 * from that order, so they stay consecutive whatever is removed, by the API or by a scan.
 *
 * All methods throw PlaylistNotFound when the playlist or item doesn't exist or isn't the user's.
 */
final readonly class PlaylistEditor
{
    /** Gap between the keys of consecutive items when appending or renumbering: room for 20 moves to the same spot. */
    public const int SORT_KEY_STEP = 1 << 20;

    private Connection $connection;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PlaylistRepository $playlists,
        private PlaylistItemRepository $items,
    ) {
        $this->connection = $entityManager->getConnection();
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

            $count = SqlValue::int($this->connection->fetchOne('SELECT COUNT(*) FROM playlist_item WHERE playlist_id = ?', [$id]));
            if ($count + \count($trackIds) > Playlist::MAX_ITEMS) {
                throw new PlaylistFull($count, \count($trackIds));
            }

            $key = SqlValue::int($this->connection->fetchOne('SELECT COALESCE(MAX(sort_key), 0) FROM playlist_item WHERE playlist_id = ?', [$id]));
            $addedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach (array_chunk($trackIds, 500) as $chunk) {
                $values = [];
                $parameters = [];
                foreach ($chunk as $trackId) {
                    $key += self::SORT_KEY_STEP;
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
            $playlistId = $playlist->getId()->toRfc4122();
            $id = $item->getId()->toRfc4122();

            $count = SqlValue::int($this->connection->fetchOne('SELECT COUNT(*) FROM playlist_item WHERE playlist_id = ?', [$playlistId]));
            $target = min($position, $count - 1);

            if ($target !== $this->items->positionOf($item)) {
                $key = $this->keyAt($playlistId, $id, $target);
                if (null === $key) {
                    $this->renumber($playlistId);
                    $key = $this->keyAt($playlistId, $id, $target) ?? throw new \LogicException('Renumbered keys always leave room.');
                }
                $this->connection->executeStatement('UPDATE playlist_item SET sort_key = ? WHERE id = ?', [$key, $id]);
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
     * A key that puts the item at the given index among the other items of the playlist, or null when its new
     * neighbours' keys are adjacent.
     */
    private function keyAt(string $playlistId, string $itemId, int $target): ?int
    {
        $keys = SqlValue::ints($this->connection->fetchFirstColumn(
            'SELECT sort_key FROM playlist_item WHERE playlist_id = ? AND id <> ? ORDER BY sort_key LIMIT 2 OFFSET ?',
            [$playlistId, $itemId, max(0, $target - 1)],
        ));
        // Keys are positive: the first item's previous neighbour is 0.
        [$previous, $next] = 0 === $target ? [0, $keys[0] ?? null] : [$keys[0] ?? throw new \LogicException('The item has a previous neighbour.'), $keys[1] ?? null];
        if (null === $next) {
            return $previous + self::SORT_KEY_STEP;
        }

        return $next - $previous >= 2 ? $previous + intdiv($next - $previous, 2) : null;
    }

    /**
     * Spreads the playlist's keys again, SORT_KEY_STEP apart, keeping the order. The new keys are above the current
     * highest one, so that no row ever takes a key another one still has (the unique index is checked row by row):
     * keys only grow, by about 10^10 at most each time, far from the 9 × 10^18 of a BIGINT.
     */
    private function renumber(string $playlistId): void
    {
        $this->connection->executeStatement(
            'UPDATE playlist_item i SET sort_key = o.highest + o.n * :step
             FROM (SELECT id, ROW_NUMBER() OVER (ORDER BY sort_key) AS n, MAX(sort_key) OVER () AS highest FROM playlist_item WHERE playlist_id = :playlist) o
             WHERE i.id = o.id',
            ['playlist' => $playlistId, 'step' => self::SORT_KEY_STEP],
        );
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
