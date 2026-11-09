<?php

namespace App\Controller;

use App\Library\ArtistImages;
use App\Library\LibraryFiles;
use App\Library\Thumbnails;
use App\Library\UnreadableImage;
use App\Repository\ArtistRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Serves a thumbnail of an artist's image (see ArtistImages and Thumbnails). Access needs a JWT
 * or the signature of the artist's coverUrl, because <img> elements can't send headers.
 */
final class ArtistCoverController extends AbstractController
{
    /** Images rarely change: let the browser keep them for a day. */
    private const int MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly ArtistRepository $artists,
        private readonly ArtistImages $artistImages,
        private readonly LibraryFiles $files,
        private readonly Thumbnails $thumbnails,
    ) {
    }

    #[Route('/api/artists/{id}/cover', name: 'artist_cover', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function __invoke(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);

        $artist = $this->artists->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Artist not found.');
        if (!$artist->hasImage()) {
            throw $this->createNotFoundException('This artist has no image.');
        }

        $imagePath = $artist->getImagePath();
        $source = (null !== $imagePath ? $this->files->path($imagePath) : $this->artistImages->downloadedFile($artist))
            ?? throw $this->createNotFoundException('The image of this artist is missing. Scan the library again.');

        try {
            $thumbnail = $this->thumbnails->of($source, 'artist-'.$artist->getId()->toRfc4122());
        } catch (UnreadableImage) {
            throw $this->createNotFoundException('The image of this artist cannot be read.');
        }

        $response = $this->files->fileResponse($thumbnail, 'image/jpeg');
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }
}
