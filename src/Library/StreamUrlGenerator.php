<?php

namespace App\Library;

use App\Entity\Track;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds the signed, expiring URL of a track's audio.
 *
 * Audio players (an <audio> element, a native media player) fetch the audio themselves
 * and can't add an Authorization header. The signature proves that the API handed out
 * the URL; it is only valid for this track and expires, unlike a JWT in the URL, which
 * would grant access to the whole API and end up in server logs.
 */
final readonly class StreamUrlGenerator
{
    /** Long enough to play an album, pause and resume; clients fetch the track again afterwards. */
    public const int LIFETIME_SECONDS = 6 * 3600;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function generate(Track $track): string
    {
        $url = $this->urlGenerator->generate(
            'track_stream',
            ['id' => $track->getId()->toRfc4122()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        // A DateInterval counts from now; a plain int would be read as an absolute Unix timestamp.
        return $this->uriSigner->sign($url, new \DateInterval(\sprintf('PT%dS', self::LIFETIME_SECONDS)));
    }
}
