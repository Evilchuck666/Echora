<?php

namespace App\Library;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Serves files of the music library (audio, covers), and images made from them, to authorized requests.
 */
final readonly class LibraryFiles
{
    public function __construct(
        private Security $security,
        private UriSigner $uriSigner,
        #[Autowire('%env(resolve:MUSIC_LIBRARY_PATH)%')]
        private string $musicLibraryPath,
    ) {
    }

    /**
     * Allows a JWT (Authorization: Bearer) or a URL signed by SignedUrlGenerator. Call it
     * before looking anything up, so that unauthenticated requests learn nothing.
     */
    public function denyUnlessAuthorized(Request $request): void
    {
        if (!$this->security->getUser() instanceof User && !$this->uriSigner->checkRequest($request)) {
            throw new UnauthorizedHttpException('Bearer', 'Send a JWT, or use the signed URL given by the API (it may have expired: fetch the resource again).');
        }
    }

    /**
     * BinaryFileResponse answers HTTP Range requests (206 Partial Content), so players
     * can seek and start playing before the whole file has been downloaded.
     *
     * @param string $path Relative to the music library folder
     */
    public function response(string $path, string $mimeType, string $missingMessage): BinaryFileResponse
    {
        return $this->fileResponse($this->path($path) ?? throw new NotFoundHttpException($missingMessage), $mimeType);
    }

    /**
     * Absolute path of a file of the library, or null when it is missing.
     *
     * @param string $path Relative to the music library folder
     */
    public function path(string $path): ?string
    {
        $root = realpath($this->musicLibraryPath);
        $file = realpath($this->musicLibraryPath.'/'.$path);

        // Paths come from the scanner, but never serve anything outside the library.
        if (false === $root || false === $file || !str_starts_with($file, $root.'/') || !is_file($file)) {
            return null;
        }

        return $file;
    }

    /**
     * @param string      $file         Absolute path, already checked by the caller
     * @param string|null $downloadName Name to save the file as: the browser or app downloads it (attachment) instead
     *                                  of playing it
     */
    public function fileResponse(string $file, string $mimeType, ?string $downloadName = null): BinaryFileResponse
    {
        // Behind Caddy (frankenphp/Caddyfile), which sets the X-Sendfile-Type and X-Accel-Mapping request headers
        // itself, the response carries an X-Accel-Redirect header instead of the file, and Caddy sends the file.
        // Without those headers (tests, bin/console server:dump...), PHP streams it as before.
        BinaryFileResponse::trustXSendfileTypeHeader();

        // private: shared caches must not keep files that require authentication.
        $response = new BinaryFileResponse($file, headers: ['Content-Type' => $mimeType], public: false, autoEtag: true, autoLastModified: true);
        if (null === $downloadName) {
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, basename($file));
        } else {
            // The header needs a plain ASCII name too, for clients that don't read the UTF-8 one (filename*).
            $fallback = preg_replace('/[^\x20-\x7e]|[%\/\\\\"]/', '_', $downloadName) ?? 'track';
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName, $fallback);
        }

        return $response;
    }
}
