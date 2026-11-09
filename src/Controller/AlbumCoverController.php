<?php

namespace App\Controller;

use App\Library\CoverFinder;
use App\Library\LibraryFiles;
use App\Repository\AlbumRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Serves the cover image of an album. Access needs a JWT or the signature of the
 * album's coverUrl, because <img> elements can't send headers.
 */
final class AlbumCoverController extends AbstractController
{
    /** Covers rarely change: let the browser keep them for a day. */
    private const int MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly AlbumRepository $albums,
        private readonly LibraryFiles $files,
    ) {
    }

    #[Route('/api/albums/{id}/cover', name: 'album_cover', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);

        $album = $this->albums->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Album not found.');
        $coverPath = $album->getCoverPath() ?? throw $this->createNotFoundException('This album has no cover.');

        $response = $this->files->response($coverPath, CoverFinder::mimeType($coverPath), 'The cover of this album is missing from the library. Scan the library again.');
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
