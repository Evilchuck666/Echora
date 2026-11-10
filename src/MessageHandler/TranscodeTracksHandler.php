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
 * Converts the tracks of a TranscodeTracks message one after the other, in their order, in the background workers
 * (messenger:consume transcodes, started by docker-entrypoint): never in a worker that serves HTTP requests. As many
 * conversions run at once as there are workers (TRANSCODE_WORKERS).
 *
 * Stops when the user asked for a newer list. Skips tracks converted or being converted in the meantime, and tracks
 * that are gone. A failure is logged and the next track is converted: nothing is retried.
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
     * @return list<string> Identifiers of the tracks converted, in the order they were
     */
    public function __invoke(TranscodeTracks $message): array
    {
        $converted = [];

        foreach ($message->trackIds as $id) {
            if (!$this->queue->isLatest($message)) {
                break;
            }

            $track = $this->tracks->find(Uuid::fromString($id));
            $source = $track instanceof Track ? $this->queue->source($track) : null;
            if (null === $source) {
                continue;
            }

            try {
                if ($this->transcoder->transcodeUnlessBusy($source, $id)) {
                    $converted[] = $id;
                }
            } catch (TranscodingFailed $e) {
                $this->logger->warning('Track {id} could not be converted to Opus ahead of time: {reason}', ['id' => $id, 'reason' => $e->getMessage(), 'exception' => $e]);
            }
        }

        return $converted;
    }
}
