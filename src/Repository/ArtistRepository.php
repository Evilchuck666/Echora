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

    /**
     * One track per album, grouped by the album's artist: where each artist's album folders are.
     *
     * @return array<string, list<string>> Track paths (relative to the library) by artist id
     */
    public function findAlbumTrackPaths(): array
    {
        $rows = $this->getEntityManager()
            ->createQuery(\sprintf('SELECT IDENTITY(al.artist) AS artistId, MIN(t.path) AS trackPath FROM %s t JOIN t.album al GROUP BY al.id, al.artist', Track::class))
            ->getScalarResult();

        $paths = [];
        foreach ($rows as $row) {
            \assert(\is_array($row) && \is_string($row['artistId']) && \is_string($row['trackPath']));
            $paths[$row['artistId']][] = $row['trackPath'];
        }

        return $paths;
    }

    /**
     * @return list<string> File names of the images downloaded from TheAudioDB
     */
    public function findDownloadedImages(): array
    {
        $names = $this->createQueryBuilder('ar')
            ->select('ar.downloadedImage')
            ->where('ar.downloadedImage IS NOT NULL')
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_filter($names, \is_string(...)));
    }
}
