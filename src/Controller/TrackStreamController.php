<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the audio file of a track.
 *
 * BinaryFileResponse answers HTTP Range requests (206 Partial Content), so players can
 * seek and start playing before the whole file has been downloaded.
 *
 * Access needs either a JWT (Authorization: Bearer) or the signature of the track's
 * streamUrl (see StreamUrlGenerator), because audio players can't send headers.
 */
final class TrackStreamController extends AbstractController
{
    public function __construct(
        private readonly TrackRepository $tracks,
        private readonly UriSigner $uriSigner,
        #[Autowire('%env(resolve:MUSIC_LIBRARY_PATH)%')]
        private readonly string $musicLibraryPath,
    ) {
    }

    #[Route('/api/tracks/{id}/stream', name: 'track_stream', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $id): BinaryFileResponse
    {
        // Before looking the track up, so that unauthenticated requests learn nothing.
        if (!$this->getUser() instanceof User && !$this->uriSigner->checkRequest($request)) {
            throw new UnauthorizedHttpException('Bearer', 'Send a JWT, or use the signed streamUrl of the track (it may have expired: fetch the track again).');
        }

        $track = $this->tracks->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Track not found.');

        $root = realpath($this->musicLibraryPath);
        $file = realpath($this->musicLibraryPath.'/'.$track->getPath());

        // The path comes from the scanner, but never serve anything outside the library.
        if (false === $root || false === $file || !str_starts_with($file, $root.'/') || !is_file($file)) {
            throw $this->createNotFoundException('The audio file of this track is missing from the library. Scan the library again.');
        }

        // private: shared caches must not keep audio that requires authentication.
        $response = new BinaryFileResponse($file, headers: ['Content-Type' => $track->getMimeType()], public: false, autoEtag: true, autoLastModified: true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($file));

        return $response;
    }
}
