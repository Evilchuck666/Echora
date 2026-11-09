<?php

namespace App\Repository;

use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Track>
 */
class TrackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Track::class);
    }

    /**
     * Size and modification time of every known file, to detect changes without reading tags.
     *
     * @return array<string, array{size: int, modifiedAt: int}> Indexed by path
     */
    public function findFileStates(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.path', 't.fileSize', 't.fileModifiedAt')
            ->getQuery()
            ->getScalarResult();

        $states = [];
        foreach ($rows as $row) {
            \assert(\is_array($row) && \is_string($row['path']) && is_numeric($row['fileSize']) && is_numeric($row['fileModifiedAt']));
            $states[$row['path']] = ['size' => (int) $row['fileSize'], 'modifiedAt' => (int) $row['fileModifiedAt']];
        }

        return $states;
    }

    /**
     * @param list<string> $paths
     *
     * @return int Number of deleted tracks
     */
    public function deleteByPaths(array $paths): int
    {
        $deleted = 0;

        foreach (array_chunk($paths, 500) as $chunk) {
            $deleted += $this->createQueryBuilder('t')
                ->delete()
                ->where('t.path IN (:paths)')
                ->setParameter('paths', $chunk)
                ->getQuery()
                ->execute();
        }

        return $deleted;
    }
}
