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

    /**
     * The current cover of every album, and the path of one of its tracks to find its folder.
     *
     * @return list<array{id: string, coverPath: string|null, trackPath: string}>
     */
    public function findCoverStates(): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(\sprintf('SELECT a.id AS id, a.coverPath AS coverPath, MIN(t.path) AS trackPath FROM %s t JOIN t.album a GROUP BY a.id, a.coverPath', Track::class))
            ->getScalarResult();

        $states = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            ['id' => $id, 'coverPath' => $coverPath, 'trackPath' => $trackPath] = $row;
            \assert(($id instanceof \Stringable || \is_string($id)) && \is_string($trackPath));
            $states[] = [
                'id' => (string) $id,
                'coverPath' => \is_string($coverPath) ? $coverPath : null,
                'trackPath' => $trackPath,
            ];
        }

        return $states;
    }

    public function deleteWithoutTracks(): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf('DELETE FROM %s a WHERE NOT EXISTS (SELECT t.id FROM %s t WHERE t.album = a)', Album::class, Track::class))
            ->execute();
    }
}
