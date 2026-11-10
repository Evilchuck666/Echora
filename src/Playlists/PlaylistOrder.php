<?php

namespace App\Playlists;

use App\Entity\Playlist;
use App\Entity\User;
use App\Ordering\SortKeys;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The order each user gives their playlists, the same on every device: new playlists come first, and a playlist can
 * be moved to any position. Kept with sort keys (App\Ordering\SortKeys, scoped by owner), like the items of a playlist.
 *
 * Creating and moving run in a transaction that first locks the owner's row (SELECT ... FOR NO KEY UPDATE): two
 * devices of the same user wait for each other instead of reading the same keys and writing clashing ones. NO KEY
 * UPDATE, not UPDATE, so that rows that merely point to the user (favourites, refresh tokens) can still be inserted
 * meanwhile. Moving a playlist doesn't change its updatedAt.
 */
final readonly class PlaylistOrder
{
    private Connection $connection;
    private SortKeys $sortKeys;

    public function __construct(private EntityManagerInterface $entityManager)
    {
        $this->connection = $entityManager->getConnection();
        $this->sortKeys = new SortKeys($this->connection, 'playlist', 'owner_id');
    }

    /**
     * A new, empty playlist, first among the user's playlists.
     */
    public function create(User $user, string $name): Playlist
    {
        return $this->entityManager->wrapInTransaction(function () use ($user, $name): Playlist {
            $this->lockOwner($user);
            $playlist = new Playlist($user, $name, $this->sortKeys->first($user->getId()->toRfc4122()));
            $this->entityManager->persist($playlist);
            $playlist->placeAt(0);

            return $playlist;
        });
    }

    /**
     * Moves the playlist to the given index (from 0) among the user's playlists, in their current order; the ones in
     * between shift by one. An index beyond the last playlist moves it to the end.
     *
     * @throws PlaylistNotFound when a request that held the lock deleted the playlist meanwhile
     */
    public function move(User $user, Playlist $playlist, int $position): void
    {
        $this->entityManager->wrapInTransaction(function () use ($user, $playlist, $position): void {
            $this->lockOwner($user);
            $id = $playlist->getId()->toRfc4122();
            if (false === $this->connection->fetchOne('SELECT 1 FROM playlist WHERE id = ?', [$id])) {
                throw new PlaylistNotFound();
            }

            $target = $this->sortKeys->move($user->getId()->toRfc4122(), $id, $position);
            $this->entityManager->refresh($playlist);
            $playlist->placeAt($target);
        });
    }

    /**
     * Index of the playlist among its owner's, from 0.
     */
    public function positionOf(Playlist $playlist): int
    {
        return $this->sortKeys->positionOf($playlist->getOwner()->getId()->toRfc4122(), $playlist->getId()->toRfc4122());
    }

    private function lockOwner(User $user): void
    {
        $this->connection->fetchOne('SELECT 1 FROM app_user WHERE id = ? FOR NO KEY UPDATE', [$user->getId()->toRfc4122()]);
    }
}
