<?php

namespace App\Controller;

use App\Library\LibraryFiles;
use App\Library\OpusTranscoder;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the audio of a FLAC track converted to Opus, with HTTP Range support. The first request
 * converts the track (OpusTranscoder); later ones are served from the media folder. Access needs a
 * JWT or the signature of the track's opusStreamUrl, like TrackStreamController.
 */
final class TrackOpusStreamController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly LibraryFiles $files,
        private readonly OpusTranscoder $transcoder,
    ) {
    }

    #[Route('/api/tracks/{id}/stream/opus', name: 'track_stream_opus', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);

        $track = $this->tracks->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Track not found.');
        if (!$track->isAvailableInOpus()) {
            throw $this->createNotFoundException('Only FLAC tracks are converted to Opus: play this one with its streamUrl.');
        }

        $source = $this->files->path($track->getPath()) ?? throw $this->createNotFoundException('The audio file of this track is missing from the library. Scan the library again.');

        return $this->files->fileResponse($this->transcoder->transcode($source, $track->getId()->toRfc4122()), OpusTranscoder::MIME_TYPE);
    }
}
