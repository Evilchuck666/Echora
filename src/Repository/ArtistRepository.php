<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Artist>
 */
class ArtistRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Artist::class);
    }

    public function deleteWithoutAlbumsOrTracks(): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf(
                'DELETE FROM %s ar WHERE NOT EXISTS (SELECT al.id FROM %s al WHERE al.artist = ar) AND NOT EXISTS (SELECT t.id FROM %s t WHERE t.artist = ar)',
                Artist::class,
                Album::class,
                Track::class,
            ))
            ->execute();
    }
}
