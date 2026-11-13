<?php

namespace App\Repository;

use App\Entity\Track;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
     * The tracks at the given paths that have an audio hash, grouped by it: those whose file is gone, which a
     * new file with the same audio may turn out to be.
     *
     * @param list<string> $paths
     *
     * @return array<string, list<array{path: string, title: string}>> Indexed by audio hash
     */
    public function findByAudioHashAmongPaths(array $paths): array
    {
        $tracks = [];

        foreach (array_chunk($paths, 500) as $chunk) {
            $rows = $this->createQueryBuilder('t')
                ->select('t.path', 't.title', 't.audioHash')
                ->where('t.path IN (:paths)')
                ->andWhere('t.audioHash IS NOT NULL')
                ->setParameter('paths', $chunk)
                ->orderBy('t.path')
                ->getQuery()
                ->getScalarResult();

            foreach ($rows as $row) {
                \assert(\is_array($row) && \is_string($row['path']) && \is_string($row['title']) && \is_string($row['audioHash']));
                $tracks[$row['audioHash']][] = ['path' => $row['path'], 'title' => $row['title']];
            }
        }

        return $tracks;
    }

    /**
     * Tracks by id, with their album and artist in the same query (to be serialized whole).
     *
     * @param list<string> $ids
     *
     * @return array<string, Track> Indexed by id (RFC 4122)
     */
    public function findWithAlbumAndArtist(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $tracks = [];
        foreach ($this->createQueryBuilder('t')
            ->addSelect('al', 'ar')
            ->innerJoin('t.album', 'al')
            ->innerJoin('t.artist', 'ar')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult() as $track) {
            $tracks[$track->getId()->toRfc4122()] = $track;
        }

        return $tracks;
    }

    /**
     * @return list<string> Identifiers (RFC 4122) of every track
     */
    public function findAllIds(): array
    {
        $ids = $this->createQueryBuilder('t')->select('t.id')->getQuery()->getSingleColumnResult();

        return array_values(array_map(static function (mixed $id): string {
            \assert($id instanceof Uuid || \is_string($id));

            return $id instanceof Uuid ? $id->toRfc4122() : Uuid::fromString($id)->toRfc4122();
        }, $ids));
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
