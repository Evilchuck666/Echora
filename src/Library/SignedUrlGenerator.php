<?php

namespace App\Library;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Builds signed, expiring URLs of the files of the library: the audio of a track (as is, or
 * converted to Opus), the cover of an album and the image of an artist.
 *
 * Audio players and <img> elements fetch those files themselves and can't add an
 * Authorization header. The signature proves that the API handed out the URL; it is
 * only valid for that file and expires, unlike a JWT in the URL, which would grant
 * access to the whole API and end up in server logs.
 */
final readonly class SignedUrlGenerator
{
    /** Long enough to play an album, pause and resume; clients fetch the track again afterwards. */
    public const int STREAM_LIFETIME_SECONDS = 6 * 3600;

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private UriSigner $uriSigner,
    ) {
    }

    public function stream(Track $track): string
    {
        // A DateInterval counts from now; a plain int would be read as an absolute Unix timestamp.
        return $this->sign('track_stream', $track->getId()->toRfc4122(), new \DateInterval(\sprintf('PT%dS', self::STREAM_LIFETIME_SECONDS)));
    }

    /**
     * The audio converted to Opus, for FLAC tracks; expires like stream().
     */
    public function opusStream(Track $track): ?string
    {
        if (!$track->isAvailableInOpus()) {
            return null;
        }

        return $this->sign('track_stream_opus', $track->getId()->toRfc4122(), new \DateInterval(\sprintf('PT%dS', self::STREAM_LIFETIME_SECONDS)));
    }

    /**
     * Expires at the end of tomorrow (UTC), so the URL stays the same all day long and
     * browsers can cache the image, while staying valid for at least 24 hours.
     */
    public function cover(Album $album): ?string
    {
        if (!$album->hasCover()) {
            return null;
        }

        return $this->albumCover($album->getId()->toRfc4122());
    }

    /**
     * The same, by the album's id, for an album known to have a cover (e.g. the covers of a playlist, read by SQL).
     */
    public function albumCover(string $albumId): string
    {
        return $this->sign('album_cover', $albumId, self::coverExpiration());
    }

    /**
     * Expires like album covers, for the same reason.
     */
    public function artistCover(Artist $artist): ?string
    {
        if (!$artist->hasImage()) {
            return null;
        }

        return $this->sign('artist_cover', $artist->getId()->toRfc4122(), self::coverExpiration());
    }

    /**
     * Expires like covers. The file name (which changes with the picture) is part of the URL, so a new picture gets a
     * new URL instead of the one browsers keep for a day.
     */
    public function avatar(User $user): ?string
    {
        $avatar = $user->getAvatar();
        if (null === $avatar) {
            return null;
        }

        $url = $this->urlGenerator->generate('user_avatar', ['id' => $user->getId()->toRfc4122(), 'v' => pathinfo($avatar, \PATHINFO_FILENAME)], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, self::coverExpiration());
    }

    public static function coverExpiration(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('tomorrow +1 day', new \DateTimeZone('UTC'));
    }

    private function sign(string $route, string $id, \DateTimeInterface|\DateInterval $expiration): string
    {
        $url = $this->urlGenerator->generate($route, ['id' => $id], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->uriSigner->sign($url, $expiration);
    }
}
