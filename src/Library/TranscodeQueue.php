<?php

namespace App\Library;

use App\Entity\Track;
use App\Entity\User;
use App\Message\TranscodeTracks;
use App\Repository\TrackRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks converted to Opus ahead of time, at a client's request (POST /api/transcodes): the ones that will be played
 * next, so that their first request doesn't wait for the conversion.
 *
 * Each request replaces the user's previous one: its tracks are sent to the background workers in one message, and
 * the worker handling an older message stops before its next track (isLatest()). Old lists never pile up, whatever
 * the number of requests: at most the track being converted is finished.
 */
final readonly class TranscodeQueue
{
    /** A list that waited this long for a worker is no longer what the user is about to hear. */
    private const int REQUEST_LIFETIME_SECONDS = 86400;

    public function __construct(
        private TrackRepository $tracks,
        private LibraryFiles $files,
        private OpusTranscoder $transcoder,
        private MessageBusInterface $bus,
        private CacheItemPoolInterface $cache, // cache.app, shared with the workers (same container)
    ) {
    }

    /**
     * Asks for the Opus versions of the given tracks, replacing the user's previous request. MP3 tracks, unknown ones
     * and those whose file is missing are left out; so are tracks already converted (which count as played now, so
     * app:transcodes:prune keeps them) and those being converted.
     *
     * @param list<string> $trackIds Identifiers (RFC 4122) of the tracks, next one first; an empty list only cancels
     *                               the previous request
     */
    public function request(User $user, array $trackIds): TranscodeRequestOutcome
    {
        $userId = $user->getId()->toRfc4122();
        $requestId = Uuid::v7()->toRfc4122();
        // First: a worker busy with the previous list stops before its next track.
        $latest = $this->cache->getItem(self::cacheKey($userId));
        $this->cache->save($latest->set($requestId)->expiresAfter(self::REQUEST_LIFETIME_SECONDS));

        $tracks = [];
        foreach ([] === $trackIds ? [] : $this->tracks->findBy(['id' => $trackIds]) as $track) {
            $tracks[$track->getId()->toRfc4122()] = $track;
        }

        $outcome = new TranscodeRequestOutcome();
        foreach ($trackIds as $id) {
            $source = isset($tracks[$id]) ? $this->source($tracks[$id]) : null;
            if (null === $source) {
                continue;
            }

            try {
                $status = $this->transcoder->check($source, $id);
            } catch (TranscodingFailed) {
                continue;
            }

            match ($status) {
                OpusStatus::Ready => $outcome->ready[] = $id,
                OpusStatus::Converting => $outcome->converting[] = $id,
                OpusStatus::Missing => $outcome->queued[] = $id,
            };
        }

        if ([] !== $outcome->queued) {
            $this->bus->dispatch(new TranscodeTracks($userId, $requestId, $outcome->queued));
        }

        return $outcome;
    }

    /**
     * Whether the list is still the latest its user asked for.
     */
    public function isLatest(TranscodeTracks $message): bool
    {
        $latest = $this->cache->getItem(self::cacheKey($message->userId));

        return $latest->isHit() && $latest->get() === $message->requestId;
    }

    /**
     * The FLAC file of a track, if it has an Opus version and its file is there.
     */
    public function source(Track $track): ?string
    {
        return $track->isAvailableInOpus() ? $this->files->path($track->getPath()) : null;
    }

    private static function cacheKey(string $userId): string
    {
        return 'transcode_request.'.$userId;
    }
}
