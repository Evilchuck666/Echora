<?php

namespace App\Controller;

use App\Entity\Track;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

/**
 * Serves the audio file of a track.
 *
 * BinaryFileResponse answers HTTP Range requests (206 Partial Content), so players can
 * seek and start playing before the whole file has been downloaded.
 */
final class TrackStreamController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(resolve:MUSIC_LIBRARY_PATH)%')]
        private readonly string $musicLibraryPath,
    ) {
    }

    #[Route('/api/tracks/{id}/stream', name: 'track_stream', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function __invoke(Track $track): BinaryFileResponse
    {
        $root = realpath($this->musicLibraryPath);
        $file = realpath($this->musicLibraryPath.'/'.$track->getPath());

        // The path comes from the scanner, but never serve anything outside the library.
        if (false === $root || false === $file || !str_starts_with($file, $root.'/') || !is_file($file)) {
            throw $this->createNotFoundException('The audio file of this track is missing from the library. Scan the library again.');
        }

        // private: once the API requires authentication, shared caches must not keep the audio.
        $response = new BinaryFileResponse($file, headers: ['Content-Type' => $track->getMimeType()], public: false, autoEtag: true, autoLastModified: true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($file));

        return $response;
    }
}
