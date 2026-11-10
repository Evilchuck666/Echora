<?php

namespace App\Playlists;

use App\Doctrine\SqlValue;
use App\Entity\Playlist;
use App\Library\SignedUrlGenerator;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fills the number of items, the total length and the covers of playlists, for a whole page at once: two queries
 * however many playlists there are, never one per playlist.
 */
final readonly class PlaylistSummaries
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SignedUrlGenerator $signedUrls,
    ) {
    }

    /**
     * @param iterable<Playlist> $playlists
     */
    public function fill(iterable $playlists): void
    {
        $byId = [];
        foreach ($playlists as $playlist) {
            $byId[$playlist->getId()->toRfc4122()] = $playlist;
        }
        if ([] === $byId) {
            return;
        }
        $connection = $this->entityManager->getConnection();
        $ids = array_keys($byId);

        $totals = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT i.playlist_id, COUNT(*) AS items, COALESCE(SUM(t.duration_ms), 0) AS duration
             FROM playlist_item i JOIN track t ON t.id = i.track_id
             WHERE i.playlist_id IN (:ids) GROUP BY i.playlist_id',
            ['ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $totals[SqlValue::string($row['playlist_id'])] = [SqlValue::int($row['items']), SqlValue::int($row['duration'])];
        }

        // The albums of each playlist in the order they first appear, those with a cover only, the first four.
        $covers = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT playlist_id, album_id FROM (
                 SELECT playlist_id, album_id, ROW_NUMBER() OVER (PARTITION BY playlist_id ORDER BY first_key) AS n FROM (
                     SELECT i.playlist_id, t.album_id, MIN(i.sort_key) AS first_key
                     FROM playlist_item i JOIN track t ON t.id = i.track_id JOIN album a ON a.id = t.album_id
                     WHERE i.playlist_id IN (:ids) AND (a.cover_path IS NOT NULL OR a.downloaded_cover IS NOT NULL)
                     GROUP BY i.playlist_id, t.album_id
                 ) albums
             ) ranked WHERE n <= :max ORDER BY playlist_id, n',
            ['ids' => $ids, 'max' => Playlist::MAX_COVERS],
            ['ids' => ArrayParameterType::STRING],
        ) as $row) {
            $covers[SqlValue::string($row['playlist_id'])][] = $this->signedUrls->albumCover(SqlValue::string($row['album_id']));
        }

        foreach ($byId as $id => $playlist) {
            [$items, $duration] = $totals[$id] ?? [0, 0];
            $playlist->setSummary($items, $duration, $covers[$id] ?? []);
        }
    }
}
