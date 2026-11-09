<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Album>
 */
class AlbumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Album::class);
    }

    public function deleteWithoutTracks(): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf('DELETE FROM %s a WHERE NOT EXISTS (SELECT t.id FROM %s t WHERE t.album = a)', Album::class, Track::class))
            ->execute();
    }
}
