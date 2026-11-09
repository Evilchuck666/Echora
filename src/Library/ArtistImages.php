<?php

namespace App\Library;

use App\Entity\Artist;
use App\Repository\ArtistRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Finds the image of every artist, as the last step of a library scan.
 *
 * The image is "artist" or "folder" (CoverFinder::ARTIST_NAMES) in the artist folder: the
 * folder that holds all of the artist's album folders, e.g. "Accept" for "Accept/1983 - Balls
 * to the Wall". When the albums are spread around, or sit at the root of the library, there is
 * no artist folder. Artists without a local image are looked up on TheAudioDB, whose image is
 * downloaded into the media folder; one that wasn't found there is asked again a month later.
 */
final readonly class ArtistImages
{
    private const string LOOK_UP_AGAIN_AFTER = '30 days';

    private const int BATCH_SIZE = 20;

    private string $directory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ArtistRepository $artists,
        private CoverFinder $finder,
        private TheAudioDb $theAudioDb,
        private ClockInterface $clock,
        #[Autowire('%app.media_dir%')]
        string $mediaDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->directory = $mediaDir.'/artists';
    }

    /**
     * Absolute path of a downloaded image, or null when the artist has none or the file is gone.
     */
    public function downloadedFile(Artist $artist): ?string
    {
        $name = $artist->getDownloadedImage();
        // The name is ours ("{id}.{ext}"), but never leave the folder.
        if (null === $name || basename($name) !== $name) {
            return null;
        }

        return is_file($this->directory.'/'.$name) ? $this->directory.'/'.$name : null;
    }

    /**
     * @param string $root     The music library folder
     * @param bool   $download Whether to look missing images up on TheAudioDB
     */
    public function update(string $root, bool $download, ScanReport $report): void
    {
        $albumTracks = $this->artists->findAlbumTrackPaths();
        $lookUpBefore = $this->clock->now()->modify('-'.self::LOOK_UP_AGAIN_AFTER);
        $download = $download && $this->theAudioDb->isEnabled();
        $pending = 0;

        foreach ($this->artists->findAll() as $artist) {
            $folder = self::artistFolder($albumTracks[$artist->getId()->toRfc4122()] ?? []);
            $image = null === $folder ? null : $this->finder->find($root.'/'.$folder, CoverFinder::ARTIST_NAMES);
            $imagePath = null === $image ? null : $folder.'/'.$image;

            if ($imagePath !== $artist->getImagePath()) {
                $artist->setImagePath($imagePath);
                ++$report->artistImagesUpdated;
            }

            // A local image wins; a downloaded file that went missing (e.g. a lost volume) is fetched again.
            if (null !== $imagePath || (null !== $artist->getDownloadedImage() && null === $this->downloadedFile($artist))) {
                $this->forgetDownload($artist);
            }

            $lookedUpAt = $artist->getImageLookedUpAt();
            if ($download && null === $report->theAudioDbFailure && !$artist->hasImage() && (null === $lookedUpAt || $lookedUpAt < $lookUpBefore)) {
                try {
                    $this->download($artist, $report);
                } catch (TheAudioDbUnavailable $e) {
                    // Stop asking for this scan; the artists left are looked up next time.
                    $report->theAudioDbFailure = $e->getMessage();
                }
            }

            if (++$pending >= self::BATCH_SIZE) {
                $this->entityManager->flush();
                $pending = 0;
            }
        }

        $this->entityManager->flush();
        $this->removeOrphanDownloads();
    }

    /**
     * The folder holding all of an artist's album folders, or null when there is none below the library root.
     *
     * @param list<string> $trackPaths One track per album of the artist, relative to the library
     */
    public static function artistFolder(array $trackPaths): ?string
    {
        if ([] === $trackPaths) {
            return null;
        }

        // The parent of each album folder, as path segments.
        $parents = array_map(static fn (string $path): array => explode('/', \dirname($path, 2)), $trackPaths);
        $common = array_shift($parents);
        foreach ($parents as $segments) {
            $length = 0;
            while ($length < \count($common) && $length < \count($segments) && $common[$length] === $segments[$length]) {
                ++$length;
            }
            $common = \array_slice($common, 0, $length);
        }

        $folder = implode('/', $common);

        return '' === $folder || '.' === $folder ? null : $folder;
    }

    /**
     * @throws TheAudioDbUnavailable
     */
    private function download(Artist $artist, ScanReport $report): void
    {
        $image = $this->theAudioDb->findArtistImage($artist->getName());
        $artist->setImageLookedUpAt($this->clock->now());
        if (null === $image) {
            return;
        }

        $name = $artist->getId()->toRfc4122().'.'.$image->extension;
        $this->filesystem->dumpFile($this->directory.'/'.$name, $image->content);
        $artist->setDownloadedImage($name);
        ++$report->artistImagesDownloaded;
    }

    private function forgetDownload(Artist $artist): void
    {
        if (null === $artist->getDownloadedImage()) {
            return;
        }
        if (null !== $file = $this->downloadedFile($artist)) {
            $this->filesystem->remove($file);
        }
        $artist->setDownloadedImage(null);
        $artist->setImageLookedUpAt(null);
    }

    /**
     * Images of artists that are gone from the library.
     */
    private function removeOrphanDownloads(): void
    {
        $kept = array_flip($this->artists->findDownloadedImages());
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (!isset($kept[basename($file)])) {
                $this->filesystem->remove($file);
            }
        }
    }
}
