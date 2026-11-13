<?php

namespace App\Library;

use App\Entity\Album;
use App\Repository\AlbumRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Finds the cover of every album, as a step of a library scan.
 *
 * The cover is looked up again in the album folder (see CoverFinder) on every scan, so a cover
 * added or replaced without touching the audio files is picked up too. Albums without a local
 * cover are looked up on TheAudioDB, whose cover is downloaded into the media folder; one that
 * wasn't found there is asked again a month later.
 */
final readonly class AlbumCovers
{
    private const string LOOK_UP_AGAIN_AFTER = '30 days';

    private const int BATCH_SIZE = 20;

    private string $directory;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlbumRepository $albums,
        private CoverFinder $finder,
        private TheAudioDb $theAudioDb,
        private ClockInterface $clock,
        #[Autowire('%app.media_dir%')]
        string $mediaDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->directory = $mediaDir.'/albums';
    }

    /**
     * Absolute path of a downloaded cover, or null when the album has none or the file is gone.
     */
    public function downloadedFile(Album $album): ?string
    {
        return $this->file($album->getDownloadedCover());
    }

    /**
     * @param string $root     The music library folder
     * @param bool   $download Whether to look missing covers up on TheAudioDB
     */
    /**
     * @param (callable(ScanPhase, int, int): void)|null $progress Told how many albums are done, out of how many
     */
    public function update(string $root, bool $download, ScanReport $report, ?callable $progress = null): void
    {
        $lookUpBefore = $this->clock->now()->modify('-'.self::LOOK_UP_AGAIN_AFTER);
        $download = $download && $this->theAudioDb->isEnabled();
        $pending = 0;

        // Albums are read as plain rows, and only loaded when something changes, so large libraries stay cheap.
        $states = $this->albums->findCoverStates();
        $total = \count($states);
        foreach ($states as $done => $state) {
            if (null !== $progress) {
                $progress(ScanPhase::Covers, (int) $done, $total);
            }
            $coverPath = $this->localCover($root, $state['trackPath']);
            $downloaded = $state['downloadedCover'];
            // A local cover wins; a downloaded file that went missing (e.g. a lost volume) is fetched again.
            $forgetDownload = null !== $downloaded && (null !== $coverPath || null === $this->file($downloaded));
            $lookUp = $download && null === $coverPath && (null === $downloaded || $forgetDownload)
                && ($forgetDownload || null === $state['coverLookedUpAt'] || $state['coverLookedUpAt'] < $lookUpBefore);

            if ($coverPath === $state['coverPath'] && !$forgetDownload && !$lookUp) {
                continue;
            }
            $album = $this->albums->find($state['id']);
            if (null === $album) {
                continue;
            }

            if ($coverPath !== $album->getCoverPath()) {
                $album->setCoverPath($coverPath);
                ++$report->coversUpdated;
            }
            if ($forgetDownload) {
                $this->forgetDownload($album);
            }
            // Checked here, not above: a 429 on an earlier album stops the lookups.
            if ($lookUp && null === $report->theAudioDbFailure) {
                try {
                    $this->download($album, $report);
                } catch (TheAudioDbUnavailable $e) {
                    // Stop asking for this scan; the albums left are looked up next time.
                    $report->theAudioDbFailure = $e->getMessage();
                }
            }

            if (++$pending >= self::BATCH_SIZE) {
                $this->flush();
                $pending = 0;
            }
        }

        $this->flush();
        if (null !== $progress) {
            $progress(ScanPhase::Covers, $total, $total);
        }
        $this->removeOrphanDownloads();
    }

    /**
     * @param string $trackPath One track of the album, relative to the library
     *
     * @return string|null Path of the cover, relative to the library
     */
    private function localCover(string $root, string $trackPath): ?string
    {
        $folder = \dirname($trackPath);
        $cover = $this->finder->find($root.'/'.$folder);

        return match (true) {
            null === $cover => null,
            '.' === $folder => $cover,
            default => $folder.'/'.$cover,
        };
    }

    private function file(?string $name): ?string
    {
        // The name is ours ("{id}.{ext}"), but never leave the folder.
        if (null === $name || basename($name) !== $name) {
            return null;
        }

        return is_file($this->directory.'/'.$name) ? $this->directory.'/'.$name : null;
    }

    /**
     * @throws TheAudioDbUnavailable
     */
    private function download(Album $album, ScanReport $report): void
    {
        $image = $this->theAudioDb->findAlbumCover($album->getArtist()->getName(), $album->getTitle());
        $album->setCoverLookedUpAt($this->clock->now());
        if (null === $image) {
            return;
        }

        $name = $album->getId()->toRfc4122().'.'.$image->extension;
        $this->filesystem->dumpFile($this->directory.'/'.$name, $image->content);
        $album->setDownloadedCover($name);
        ++$report->coversDownloaded;
    }

    private function forgetDownload(Album $album): void
    {
        if (null !== $file = $this->downloadedFile($album)) {
            $this->filesystem->remove($file);
        }
        $album->setDownloadedCover(null);
        $album->setCoverLookedUpAt(null);
    }

    /**
     * Writes the batch and frees memory: albums are loaded one by one, none is kept.
     */
    private function flush(): void
    {
        $this->entityManager->flush();
        $this->entityManager->clear();
    }

    /**
     * Covers of albums that are gone from the library.
     */
    private function removeOrphanDownloads(): void
    {
        $kept = array_flip($this->albums->findDownloadedCovers());
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            if (!isset($kept[basename($file)])) {
                $this->filesystem->remove($file);
            }
        }
    }
}
