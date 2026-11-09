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
     * @return list<array{id: string, coverPath: string|null, downloadedCover: string|null, coverLookedUpAt: \DateTimeImmutable|null, trackPath: string}>
     */
    public function findCoverStates(): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(\sprintf(
                'SELECT a.id AS id, a.coverPath AS coverPath, a.downloadedCover AS downloadedCover, a.coverLookedUpAt AS coverLookedUpAt, MIN(t.path) AS trackPath
                FROM %s t JOIN t.album a GROUP BY a.id, a.coverPath, a.downloadedCover, a.coverLookedUpAt',
                Track::class,
            ))
            // Not getScalarResult(): it would give the date as a string.
            ->getArrayResult();

        $states = [];
        foreach ($rows as $row) {
            \assert(\is_array($row));
            ['id' => $id, 'coverPath' => $coverPath, 'downloadedCover' => $downloadedCover, 'coverLookedUpAt' => $lookedUpAt, 'trackPath' => $trackPath] = $row;
            \assert(($id instanceof \Stringable || \is_string($id)) && \is_string($trackPath));
            $states[] = [
                'id' => (string) $id,
                'coverPath' => \is_string($coverPath) ? $coverPath : null,
                'downloadedCover' => \is_string($downloadedCover) ? $downloadedCover : null,
                'coverLookedUpAt' => $lookedUpAt instanceof \DateTimeImmutable ? $lookedUpAt : null,
                'trackPath' => $trackPath,
            ];
        }

        return $states;
    }

    /**
     * @return list<string> File names of the covers downloaded from TheAudioDB
     */
    public function findDownloadedCovers(): array
    {
        $names = $this->createQueryBuilder('a')
            ->select('a.downloadedCover')
            ->where('a.downloadedCover IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($names, \is_string(...)));
    }

    public function deleteWithoutTracks(): void
    {
        $this->getEntityManager()
            ->createQuery(\sprintf('DELETE FROM %s a WHERE NOT EXISTS (SELECT t.id FROM %s t WHERE t.album = a)', Album::class, Track::class))
            ->execute();
    }
}
