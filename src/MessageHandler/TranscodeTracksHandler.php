<?php

namespace App\MessageHandler;

use App\Entity\Track;
use App\Library\OpusTranscoder;
use App\Library\TranscodeQueue;
use App\Library\TranscodingFailed;
use App\Message\TranscodeTracks;
use App\Repository\TrackRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Converts the next track of a TranscodeTracks message in the background workers (messenger:consume transcodes,
 * started by docker-entrypoint): never in a worker that serves HTTP requests. As many conversions run at once as
 * there are workers (TRANSCODE_WORKERS).
 *
 * One conversion per message: the rest of the list goes back to the end of the queue, so the lists of every device
 * (and every user) take turns, each in its own order. Tracks converted or being converted in the meantime, and tracks
 * that are gone, are skipped without using the turn. A list is dropped when its device sent a newer one. A failure is
 * logged and the list goes on: nothing is retried.
 */
#[AsMessageHandler]
final readonly class TranscodeTracksHandler
{
    public function __construct(
        private TranscodeQueue $queue,
        private TrackRepository $tracks,
        private OpusTranscoder $transcoder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string|null Identifier of the track converted, if one was
     */
    public function __invoke(TranscodeTracks $message): ?string
    {
        $trackIds = $message->trackIds;

        while (null !== $id = array_shift($trackIds)) {
            if (!$this->queue->isLatest($message)) {
                return null;
            }

            $track = $this->tracks->find(Uuid::fromString($id));
            $source = $track instanceof Track ? $this->queue->source($track) : null;
            if (null === $source) {
                continue;
            }

            try {
                if (!$this->transcoder->transcodeUnlessBusy($source, $id)) {
                    continue; // Ready or being converted: nothing was done, the turn goes on.
                }
                $converted = $id;
            } catch (TranscodingFailed $e) {
                $this->logger->warning('Track {id} could not be converted to Opus ahead of time: {reason}', ['id' => $id, 'reason' => $e->getMessage(), 'exception' => $e]);
                $converted = null;
            }

            if ([] !== $trackIds && $this->queue->isLatest($message)) {
                $this->queue->requeue($message, $trackIds);
            }

            return $converted;
        }

        return null;
    }
}
