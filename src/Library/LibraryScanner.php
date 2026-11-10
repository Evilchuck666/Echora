<?php

namespace App\Library;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Repository\AlbumRepository;
use App\Repository\ArtistRepository;
use App\Repository\TrackRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Synchronises the database with the FLAC and MP3 files of the music library folder.
 *
 * New files are added, files whose size or modification time changed are read again,
 * missing files are removed, and albums and artists left without tracks are deleted.
 * A new file with the same audio as a missing one (TagReader::audioHash()) was moved or
 * renamed: its track is kept, with the new path and tags, so whatever points to the track
 * (favorites, playlists) keeps working.
 * Then the cover of every album is looked up again (see AlbumCovers), so a cover added
 * or replaced without touching the audio files is picked up too, and so is the image of
 * every artist (see ArtistImages). Both are downloaded from TheAudioDB when their folder has none.
 * The files themselves are never modified: the folder is mounted read-only.
 */
final class LibraryScanner
{
    private const int BATCH_SIZE = 100;

    /** @var array<string, Artist> */
    private array $artistCache = [];

    /** @var array<string, Album> */
    private array $albumCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackRepository $tracks,
        private readonly AlbumRepository $albums,
        private readonly ArtistRepository $artists,
        private readonly TagReader $tagReader,
        private readonly AlbumCovers $albumCovers,
        private readonly ArtistImages $artistImages,
        #[Autowire('%env(resolve:MUSIC_LIBRARY_PATH)%')]
        private readonly string $musicLibraryPath,
    ) {
    }

    /**
     * @param string|null $directory Folder to scan; defaults to MUSIC_LIBRARY_PATH
     * @param bool        $full      Read every file again, even unchanged ones (e.g. after an update that reads more tags)
     * @param bool        $download  Look up the albums without a cover and the artists without an image on TheAudioDB
     *
     * @throws LibraryUnavailable
     */
    public function scan(?string $directory = null, bool $full = false, bool $download = true): ScanReport
    {
        $root = $directory ?? $this->musicLibraryPath;
        $known = $this->tracks->findFileStates();

        if (!is_dir($root)) {
            throw new LibraryUnavailable(\sprintf('The music library folder "%s" does not exist.', $root));
        }

        $files = (new Finder())
            ->files()
            ->in($root)
            ->name('/\.(flac|mp3)$/i')
            ->ignoreUnreadableDirs()
            ->sortByName();

        // Listed first: the tracks whose file is gone have to be known before reading the new files.
        $seen = [];
        foreach ($files as $file) {
            $seen[$file->getRelativePathname()] = $file;
        }

        if ([] === $seen && [] !== $known) {
            throw new LibraryUnavailable(\sprintf('No audio files found in "%s", but the library has %d tracks. Is the music folder mounted? Nothing was removed.', $root, \count($known)));
        }

        $report = new ScanReport();
        $vanished = $this->tracks->findByAudioHashAmongPaths(array_map(strval(...), array_keys(array_diff_key($known, $seen))));
        $movedFrom = [];
        $pending = 0;

        foreach ($seen as $path => $file) {
            $path = (string) $path;
            $state = $known[$path] ?? null;

            if (!$full && null !== $state && $state['size'] === (int) $file->getSize() && $state['modifiedAt'] === (int) $file->getMTime()) {
                ++$report->unchanged;
                continue;
            }

            try {
                $metadata = $this->tagReader->read($file);
            } catch (UnreadableAudioFile $e) {
                $report->failed[$path] = $e->getMessage();
                continue;
            }

            if (null !== $state) {
                $this->update($path, $metadata);
                ++$report->updated;
            } elseif (null !== $from = $this->claimMovedTrack($vanished, $metadata)) {
                $this->move($from, $path, $metadata);
                $movedFrom[$from] = true;
                ++$report->moved;
            } else {
                $this->add($path, $metadata);
                ++$report->added;
            }

            if (++$pending >= self::BATCH_SIZE) {
                $this->flush();
                $pending = 0;
            }
        }

        $this->flush();

        $report->removed = $this->tracks->deleteByPaths(array_map(strval(...), array_keys(array_diff_key($known, $seen, $movedFrom))));
        $this->albums->deleteWithoutTracks();
        $this->artists->deleteWithoutAlbumsOrTracks();
        $this->albumCovers->update($root, $download, $report);
        $this->artistImages->update($root, $download, $report);

        return $report;
    }

    private function add(string $path, AudioMetadata $metadata): void
    {
        [$album, $artist] = $this->resolveAlbumAndArtist($metadata);
        $this->entityManager->persist(new Track($path, $album, $artist, $metadata));
    }

    /**
     * The path of the missing track that a new file is, if its audio says so: the only missing track with the
     * same audio, or, when several have it (e.g. identical silent tracks), the only one with the same title.
     * A claimed track is taken out of $vanished, so two new files never get the same track.
     *
     * @param array<string, list<array{path: string, title: string}>> $vanished Missing tracks by audio hash
     */
    private function claimMovedTrack(array &$vanished, AudioMetadata $metadata): ?string
    {
        $hash = $metadata->audioHash;
        $candidates = null === $hash ? [] : $vanished[$hash] ?? [];

        if (1 !== \count($candidates)) {
            $candidates = array_filter($candidates, static fn (array $track): bool => 0 === strcasecmp($track['title'], $metadata->title));
            if (1 !== \count($candidates)) {
                return null;
            }
        }

        \assert(null !== $hash);
        $index = array_key_first($candidates);
        $path = $candidates[$index]['path'];
        $remaining = $vanished[$hash];
        unset($remaining[$index]);
        if ([] === $remaining) {
            unset($vanished[$hash]);
        } else {
            $vanished[$hash] = array_values($remaining);
        }

        return $path;
    }

    private function move(string $from, string $to, AudioMetadata $metadata): void
    {
        $track = $this->tracks->findOneBy(['path' => $from]);
        if (null === $track) {
            $this->add($to, $metadata);

            return;
        }

        [$album, $artist] = $this->resolveAlbumAndArtist($metadata);
        $track->moveTo($to);
        $track->update($album, $artist, $metadata);
    }

    private function update(string $path, AudioMetadata $metadata): void
    {
        $track = $this->tracks->findOneBy(['path' => $path]);
        if (null === $track) {
            $this->add($path, $metadata);

            return;
        }

        [$album, $artist] = $this->resolveAlbumAndArtist($metadata);
        $track->update($album, $artist, $metadata);
    }

    /**
     * @return array{Album, Artist}
     */
    private function resolveAlbumAndArtist(AudioMetadata $metadata): array
    {
        $albumArtist = $this->artist($metadata->albumArtist);
        $key = $albumArtist->getId()->toRfc4122()."\0".$metadata->album;

        $album = $this->albumCache[$key] ??= $this->albums->findOneBy(['artist' => $albumArtist, 'title' => $metadata->album])
            ?? $this->persist(new Album($albumArtist, $metadata->album, $metadata->year));

        if (null !== $metadata->year && $album->getYear() !== $metadata->year) {
            $album->setYear($metadata->year);
        }

        return [$album, $this->artist($metadata->artist)];
    }

    private function artist(string $name): Artist
    {
        return $this->artistCache[$name] ??= $this->artists->findOneBy(['name' => $name])
            ?? $this->persist(new Artist($name));
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function persist(object $entity): object
    {
        $this->entityManager->persist($entity);

        return $entity;
    }

    /**
     * Writes the batch and frees memory, so large libraries can be scanned.
     */
    private function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
        $this->artistCache = [];
        $this->albumCache = [];
    }
}
