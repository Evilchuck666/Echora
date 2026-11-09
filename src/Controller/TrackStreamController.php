<?php

namespace App\Controller;

use App\Library\LibraryFiles;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the audio file of a track, with HTTP Range support. Access needs a JWT or the
 * signature of the track's streamUrl, because audio players can't send headers.
 */
final class TrackStreamController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly LibraryFiles $files,
    ) {
    }

    #[Route('/api/tracks/{id}/stream', name: 'track_stream', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);

        $track = $this->tracks->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Track not found.');

        return $this->files->response($track->getPath(), $track->getMimeType(), 'The audio file of this track is missing from the library. Scan the library again.');
    }
}
