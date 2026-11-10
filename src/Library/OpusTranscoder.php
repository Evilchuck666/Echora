<?php

namespace App\Library;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Converts FLAC tracks to Opus, for clients that would rather save bandwidth than get the original file.
 *
 * A track is converted the first time its Opus version is asked for, then served from the media folder
 * like any other file (by Caddy, with byte ranges). The file name holds a hash of the source's path,
 * size and modification time and of the bitrate, so a changed FLAC file is converted again, and its
 * older version is removed then.
 *
 * The access time of an Opus file is the last time it was asked for: prune() removes the ones nobody
 * played for a while. The modification time is left alone, as Caddy derives the ETag from it and players
 * send it back (If-Range) while they fetch the rest of the file.
 */
final readonly class OpusTranscoder
{
    /** Indistinguishable from the original for nearly everyone, at about a quarter of the size of a FLAC file. */
    public const int BITRATE_KBPS = 256;

    public const string MIME_TYPE = 'audio/ogg; codecs=opus';

    /** A track takes a few seconds to convert: this only stops an encoder that hangs. */
    private const int TIMEOUT_SECONDS = 300;

    /** Partial files older than this were left by a conversion that was interrupted (e.g. by a restart). */
    private const int LEFTOVER_SECONDS = 3600;

    /** Players send several requests for one file: record its use at most once an hour. */
    private const int ACCESS_TIME_PRECISION_SECONDS = 3600;

    private const string FILE_PATTERN = '/^track-([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})-[0-9a-f]{16}\.opus$/';

    private string $directory;

    public function __construct(
        #[Autowire('%app.media_dir%')]
        string $mediaDir,
        private LockFactory $locks,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->directory = $mediaDir.'/transcodes';
    }

    /**
     * @param string $source  Absolute path of the FLAC file
     * @param string $trackId Identifier of the track (RFC 4122): its older Opus files are removed
     *
     * @return string Absolute path of the Opus file
     *
     * @throws TranscodingFailed
     */
    public function transcode(string $source, string $trackId): string
    {
        $stat = @stat($source) ?: throw new TranscodingFailed(\sprintf('"%s" cannot be read.', $source));
        $file = \sprintf('%s/track-%s-%s.opus', $this->directory, $trackId, substr(hash('xxh128', $source."\0".$stat['size']."\0".$stat['mtime']."\0".self::BITRATE_KBPS), 0, 16));

        if (!is_file($file)) {
            // A player often sends several requests at once: one converts the track, the others wait for its file.
            $lock = $this->locks->createLock('opus-transcode-'.$trackId, self::TIMEOUT_SECONDS + 60);
            $lock->acquire(true);
            try {
                if (!is_file($file)) {
                    $this->encode($source, $file);
                    $this->removeOlderVersions($trackId, $file);
                }
            } finally {
                $lock->release();
            }
        }

        $this->markAsUsed($file);

        return $file;
    }

    /**
     * Removes the Opus files that nobody asked for since a given time, those of tracks that are no longer
     * in the library, and partial files left by interrupted conversions.
     *
     * @param list<string> $trackIds Identifiers (RFC 4122) of every track of the library
     *
     * @return int Number of files removed
     */
    public function prune(\DateTimeInterface $unusedSince, array $trackIds): int
    {
        $known = array_fill_keys($trackIds, true);
        $removed = 0;

        foreach (glob($this->directory.'/*') ?: [] as $file) {
            clearstatcache(true, $file);
            if (1 === preg_match(self::FILE_PATTERN, basename($file), $matches)) {
                $obsolete = !isset($known[$matches[1]]) || (int) @fileatime($file) < $unusedSince->getTimestamp();
            } else {
                $obsolete = (int) @filemtime($file) < time() - self::LEFTOVER_SECONDS;
            }

            if ($obsolete) {
                $this->filesystem->remove($file);
                ++$removed;
            }
        }

        return $removed;
    }

    /**
     * @throws TranscodingFailed
     */
    private function encode(string $source, string $file): void
    {
        $this->filesystem->mkdir($this->directory);
        // Written next to its final name, then renamed: a request never serves half a file.
        $partial = $file.'.'.bin2hex(random_bytes(4)).'.part';
        // Tags are kept; embedded pictures are not: clients get the cover from the album's coverUrl.
        $process = new Process(['opusenc', '--quiet', '--bitrate', (string) self::BITRATE_KBPS, '--discard-pictures', $source, $partial]);
        $process->setTimeout(self::TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            $this->filesystem->remove($partial);

            throw new TranscodingFailed(\sprintf('Converting "%s" took more than %d seconds.', $source, self::TIMEOUT_SECONDS), previous: $e);
        }

        if (!$process->isSuccessful() || !is_file($partial)) {
            $this->filesystem->remove($partial);

            throw new TranscodingFailed(\sprintf('opusenc could not convert "%s": %s', $source, trim($process->getErrorOutput())));
        }

        $this->filesystem->rename($partial, $file, true);
    }

    private function removeOlderVersions(string $trackId, string $file): void
    {
        foreach (glob(\sprintf('%s/track-%s-*.opus', $this->directory, $trackId)) ?: [] as $older) {
            if ($older !== $file) {
                $this->filesystem->remove($older);
            }
        }
    }

    private function markAsUsed(string $file): void
    {
        clearstatcache(true, $file);
        $accessedAt = @fileatime($file);
        $modifiedAt = @filemtime($file);
        if (false !== $accessedAt && false !== $modifiedAt && $accessedAt < time() - self::ACCESS_TIME_PRECISION_SECONDS) {
            @touch($file, $modifiedAt, time());
        }
    }
}
