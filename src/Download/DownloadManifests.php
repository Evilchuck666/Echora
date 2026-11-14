<?php

namespace App\Download;

use App\Doctrine\SqlValue;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Playlist;
use App\Entity\Track;
use App\Entity\User;
use App\Library\LibraryFiles;
use App\Library\OpusTranscoder;
use App\Library\SignedUrlGenerator;
use App\Repository\PlaylistRepository;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * What a client downloads to play a track, an album, an artist or a playlist offline (docs/decisions/0020): every
 * track once, with a signed URL to its file (valid a day), its format, size and where to save it, and the album
 * covers. The client downloads the files itself, one by one and resumably (Caddy serves them with byte ranges): the
 * server builds no archive.
 */
final readonly class DownloadManifests
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TrackRepository $tracks,
        private PlaylistRepository $playlists,
        private SignedUrlGenerator $signedUrls,
        private LibraryFiles $files,
        private OpusTranscoder $transcoder,
    ) {
    }

    /**
     * @param 'tracks'|'albums'|'artists'|'playlists' $kind
     *
     * @return array<string, mixed>|null Null when there is no such item (or the playlist isn't the user's)
     */
    public function of(string $kind, string $id, DownloadFormat $format, User $user): ?array
    {
        $uuid = Uuid::fromString($id);

        switch ($kind) {
            case 'tracks':
                $track = $this->entityManager->find(Track::class, $uuid);

                return null === $track ? null : $this->manifest('track', $id, $track->getTitle(), [$track], $format);
            case 'albums':
                $album = $this->entityManager->find(Album::class, $uuid);

                return null === $album ? null : $this->manifest('album', $id, $album->getTitle(), $this->tracks->findInLibraryOrder(albumId: $uuid), $format);
            case 'artists':
                $artist = $this->entityManager->find(Artist::class, $uuid);

                return null === $artist ? null : $this->manifest('artist', $id, $artist->getName(), $this->tracks->findInLibraryOrder(artistId: $uuid), $format);
            default:
                $playlist = $this->playlists->findOwned($user, $id);

                return null === $playlist ? null : $this->playlistManifest($playlist, $format);
        }
    }

    /**
     * A playlist: its tracks once each, and the order of its items (a track may come several times), to rebuild it.
     *
     * @return array<string, mixed>
     */
    private function playlistManifest(Playlist $playlist, DownloadFormat $format): array
    {
        $items = SqlValue::strings($this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT track_id FROM playlist_item WHERE playlist_id = ? ORDER BY sort_key',
            [$playlist->getId()->toRfc4122()],
        ));
        $tracks = $this->tracks->findWithAlbumAndArtist(array_values(array_unique($items)));
        $ordered = [];
        foreach (array_unique($items) as $id) {
            if (isset($tracks[$id])) {
                $ordered[] = $tracks[$id];
            }
        }

        return $this->manifest('playlist', $playlist->getId()->toRfc4122(), $playlist->getName(), $ordered, $format) + ['items' => $items];
    }

    /**
     * @param list<Track> $tracks
     *
     * @return array<string, mixed>
     */
    private function manifest(string $kind, string $id, string $name, array $tracks, DownloadFormat $format): array
    {
        $severalDiscs = [];
        foreach ($tracks as $track) {
            $albumId = $track->getAlbum()->getId()->toRfc4122();
            $severalDiscs[$albumId] = ($severalDiscs[$albumId] ?? false) || ($track->getDiscNumber() ?? 1) > 1;
        }

        $entries = [];
        $covers = [];
        $totalSize = 0;
        $sizesKnown = true;
        foreach ($tracks as $track) {
            $entry = $this->entry($track, $format, $severalDiscs[$track->getAlbum()->getId()->toRfc4122()]);
            $entries[] = $entry;
            null === $entry['size'] ? $sizesKnown = false : $totalSize += $entry['size'];

            $album = $track->getAlbum();
            $cover = $this->signedUrls->cover($album);
            if (null !== $cover && !isset($covers[$album->getId()->toRfc4122()])) {
                $covers[$album->getId()->toRfc4122()] = [
                    'album' => $album->getId()->toRfc4122(),
                    'savePath' => DownloadNames::coverPath($album, self::coverExtension($album)),
                    'url' => $cover,
                ];
            }
        }

        return [
            'kind' => $kind,
            'id' => $id,
            'name' => $name,
            'format' => $format->value,
            'expiresAt' => SignedUrlGenerator::downloadExpiration()->format(\DATE_ATOM),
            'trackCount' => \count($entries),
            'totalSize' => $totalSize,
            'totalSizeComplete' => $sizesKnown,
            'tracks' => $entries,
            'covers' => array_values($covers),
        ];
    }

    /**
     * @return array{id: string, title: string, artist: string, album: string, discNumber: ?int, trackNumber: ?int, durationMs: ?int, format: string, mimeType: string, size: ?int, savePath: string, url: string}
     */
    private function entry(Track $track, DownloadFormat $format, bool $severalDiscs): array
    {
        $id = $track->getId()->toRfc4122();
        $opusUrl = DownloadFormat::Opus === $format ? $this->signedUrls->opusDownload($track) : null;

        if (null !== $opusUrl) {
            $source = $this->files->path($track->getPath());
            // Known once converted; the first download of the track converts it (a few seconds).
            $size = null === $source ? null : $this->transcoder->convertedSize($source, $id);
            [$fileFormat, $mimeType, $extension, $url] = ['opus', OpusTranscoder::MIME_TYPE, 'opus', $opusUrl];
        } else {
            $size = $track->getFileSize();
            [$fileFormat, $mimeType, $extension, $url] = [$track->getFormat(), $track->getMimeType(), $track->getFormat(), $this->signedUrls->download($track)];
        }

        return [
            'id' => $id,
            'title' => $track->getTitle(),
            'artist' => $track->getArtist()->getName(),
            'album' => $track->getAlbum()->getTitle(),
            'discNumber' => $track->getDiscNumber(),
            'trackNumber' => $track->getTrackNumber(),
            'durationMs' => $track->getDurationMs(),
            'format' => $fileFormat,
            'mimeType' => $mimeType,
            'size' => $size,
            'savePath' => DownloadNames::trackPath($track, $extension, $severalDiscs),
            'url' => $url,
        ];
    }

    private static function coverExtension(Album $album): string
    {
        $extension = strtolower(pathinfo($album->getCoverPath() ?? $album->getDownloadedCover() ?? '', \PATHINFO_EXTENSION));

        return \in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
    }
}
