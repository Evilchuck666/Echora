<?php

namespace App\Repository;

use App\Entity\Playlist;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Playlist>
 */
class PlaylistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Playlist::class);
    }

    /**
     * The playlist, if it exists and belongs to the user: nobody else's playlists exist for them.
     */
    public function findOwned(User $user, string $id): ?Playlist
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->findOneBy(['id' => Uuid::fromString($id), 'owner' => $user]);
    }

    /**
     * Marks as changed the playlists that hold any of the tracks at these paths, before a scan deletes them (their
     * items go with them, ON DELETE CASCADE).
     *
     * @param list<string> $paths
     */
    public function touchPlaylistsOfTracksAt(array $paths): void
    {
        foreach (array_chunk($paths, 500) as $chunk) {
            $this->getEntityManager()->getConnection()->executeStatement(
                'UPDATE playlist SET updated_at = :now WHERE id IN (SELECT i.playlist_id FROM playlist_item i JOIN track t ON t.id = i.track_id WHERE t.path IN (:paths))',
                ['now' => new \DateTimeImmutable(), 'paths' => $chunk],
                ['now' => 'datetime_immutable', 'paths' => ArrayParameterType::STRING],
            );
        }
    }
}
