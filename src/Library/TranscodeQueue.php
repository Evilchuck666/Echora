<?php

namespace App\Library;

use App\Entity\Track;
use App\Entity\User;
use App\Message\TranscodeTracks;
use App\Repository\TrackRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Tracks converted to Opus ahead of time, at a client's request (POST /api/transcodes): the ones that will be played
 * next on a device, so that their first request doesn't wait for the conversion.
 *
 * Each device of an account has its own list: a request replaces the previous one of the same device only. The
 * account keeps the lists of its MAX_DEVICES most recent devices; the lists of the others are dropped, so that a
 * client sending a new device id with every request can't pile them up. The latest request of each device is kept in
 * cache.app, which the background workers check before every track (isLatest()).
 */
final readonly class TranscodeQueue
{
    public const int MAX_DEVICES = 5;

    /** A list that waited this long for a worker is no longer what the user is about to hear. */
    private const int REQUEST_LIFETIME_SECONDS = 86400;

    public function __construct(
        private TrackRepository $tracks,
        private LibraryFiles $files,
        private OpusTranscoder $transcoder,
        private MessageBusInterface $bus,
        private LockFactory $locks,
        private CacheItemPoolInterface $cache, // cache.app, shared with the workers (same container)
    ) {
    }

    /**
     * Asks for the Opus versions of the given tracks, replacing the previous request of the same device. MP3 tracks,
     * unknown ones and those whose file is missing are left out; so are tracks already converted (which count as
     * played now, so app:transcodes:prune keeps them) and those being converted.
     *
     * @param string       $deviceId Identifier of the device, chosen by the client
     * @param list<string> $trackIds Identifiers (RFC 4122) of the tracks, next one first; an empty list only cancels
     *                               the previous request of the device
     */
    public function request(User $user, string $deviceId, array $trackIds): TranscodeRequestOutcome
    {
        $userId = $user->getId()->toRfc4122();
        $requestId = Uuid::v7()->toRfc4122();
        // First: a worker busy with the previous list of the device stops before its next track.
        $this->remember($userId, $deviceId, $requestId);

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
            $this->bus->dispatch(new TranscodeTracks($userId, $deviceId, $requestId, $outcome->queued));
        }

        return $outcome;
    }

    /**
     * Whether the list is still the latest its device asked for (and the device one of the account's most recent).
     * Reads what other requests write: the answer changes over time.
     *
     * @phpstan-impure
     */
    public function isLatest(TranscodeTracks $message): bool
    {
        return ($this->latestRequests($message->userId)[$message->deviceId] ?? null) === $message->requestId;
    }

    /**
     * Sends the rest of a list back to the end of the queue, so that the lists of every device take turns.
     *
     * @param list<string> $trackIds
     */
    public function requeue(TranscodeTracks $message, array $trackIds): void
    {
        $this->bus->dispatch(new TranscodeTracks($message->userId, $message->deviceId, $message->requestId, $trackIds));
    }

    /**
     * The FLAC file of a track, if it has an Opus version and its file is there.
     */
    public function source(Track $track): ?string
    {
        return $track->isAvailableInOpus() ? $this->files->path($track->getPath()) : null;
    }

    /**
     * Records the request as the latest of its device, and the device as the account's most recent one.
     */
    private function remember(string $userId, string $deviceId, string $requestId): void
    {
        // Two devices of the account may send their lists at the same time: neither must be lost.
        $lock = $this->locks->createLock('transcode-requests-'.$userId, 30);
        $lock->acquire(true);
        try {
            $latest = $this->latestRequests($userId);
            unset($latest[$deviceId]);
            $latest = \array_slice([$deviceId => $requestId] + $latest, 0, self::MAX_DEVICES, true);

            $item = $this->cache->getItem(self::cacheKey($userId));
            $this->cache->save($item->set($latest)->expiresAfter(self::REQUEST_LIFETIME_SECONDS));
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, string> The latest request of each of the account's most recent devices, most recent first
     */
    private function latestRequests(string $userId): array
    {
        $item = $this->cache->getItem(self::cacheKey($userId));
        $latest = $item->isHit() ? $item->get() : null;
        if (!\is_array($latest)) {
            return [];
        }

        $requests = [];
        foreach ($latest as $deviceId => $requestId) {
            if (\is_string($deviceId) && \is_string($requestId)) {
                $requests[$deviceId] = $requestId;
            }
        }

        return $requests;
    }

    private static function cacheKey(string $userId): string
    {
        return 'transcode_requests.'.$userId;
    }
}
