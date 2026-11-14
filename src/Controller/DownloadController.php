<?php

namespace App\Controller;

use App\Download\DownloadFormat;
use App\Download\DownloadManifests;
use App\Download\DownloadNames;
use App\Entity\User;
use App\Library\LibraryFiles;
use App\Library\OpusTranscoder;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Downloads, to play music offline (docs/decisions/0020): the manifest of a track, album, artist or playlist
 * (App\Download\DownloadManifests), and the files it points to, as attachments with a readable name. Files are served
 * by Caddy, with byte ranges, to a JWT or the signature of their URL. Documented by App\OpenApi\DownloadsOpenApiFactory.
 */
final class DownloadController extends AbstractController
{
    public function __construct(
        private readonly DownloadManifests $manifests,
        private readonly TrackRepository $tracks,
        private readonly LibraryFiles $files,
        private readonly OpusTranscoder $transcoder,
    ) {
    }

    #[Route('/api/{kind}/{id}/download', name: 'download_manifest', requirements: ['kind' => 'tracks|albums|artists|playlists', 'id' => Requirement::UUID], methods: ['GET'])]
    public function manifest(
        string $kind,
        string $id,
        #[MapQueryParameter(validationFailedStatusCode: Response::HTTP_UNPROCESSABLE_ENTITY)]
        DownloadFormat $format = DownloadFormat::Original,
    ): JsonResponse {
        $user = $this->getUser();
        \assert($user instanceof User);
        \assert(\in_array($kind, ['tracks', 'albums', 'artists', 'playlists'], true));

        $manifest = $this->manifests->of($kind, $id, $format, $user) ?? throw $this->createNotFoundException('Nothing to download with this id.');

        return $this->json($manifest);
    }

    #[Route('/api/tracks/{id}/file', name: 'track_file', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function originalFile(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);
        $track = $this->tracks->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Track not found.');
        $file = $this->files->path($track->getPath()) ?? throw $this->createNotFoundException('The audio file of this track is missing from the library. Scan the library again.');

        return $this->files->fileResponse($file, $track->getMimeType(), DownloadNames::trackFileName($track, $track->getFormat()));
    }

    #[Route('/api/tracks/{id}/file/opus', name: 'track_file_opus', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function opusFile(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);
        $track = $this->tracks->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('Track not found.');
        if (!$track->isAvailableInOpus()) {
            throw $this->createNotFoundException('Only FLAC tracks are converted to Opus: download this one as it is.');
        }
        $source = $this->files->path($track->getPath()) ?? throw $this->createNotFoundException('The audio file of this track is missing from the library. Scan the library again.');

        return $this->files->fileResponse($this->transcoder->transcode($source, $track->getId()->toRfc4122()), OpusTranscoder::MIME_TYPE, DownloadNames::trackFileName($track, 'opus'));
    }
}
