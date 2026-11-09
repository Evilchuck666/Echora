<?php

namespace App\Tests\Api;

use App\Library\TheAudioDb;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Replaces TheAudioDB with a fake that never reaches the network.
 */
trait FakesTheAudioDb
{
    /** @var list<string> Artists searched on the fake TheAudioDB */
    private array $artistSearches = [];

    /** @var list<string> Albums searched on the fake TheAudioDB, as "artist / title" */
    private array $albumSearches = [];

    /**
     * Knows the given artists and albums, with a 16×16 JPEG each.
     *
     * @param list<string> $artists Artist names
     * @param list<string> $albums  Albums, as "artist / title"
     * @param int          $status  Status of every search, e.g. 429
     */
    private function fakeTheAudioDb(array $artists = [], array $albums = [], int $status = 200): void
    {
        $image = imagecreatetruecolor(16, 16);
        \assert(false !== $image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $client = new MockHttpClient(function (string $method, string $url) use ($artists, $albums, $status, $jpeg): MockResponse {
            if (str_contains($url, '/images/')) {
                return new MockResponse($jpeg, ['response_headers' => ['content-type' => 'image/jpeg']]);
            }

            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $artist = $query['s'] ?? null;
            \assert(\is_string($artist));

            if (str_contains($url, '/searchalbum.php')) {
                $title = $query['a'] ?? null;
                \assert(\is_string($title));
                $this->albumSearches[] = $artist.' / '.$title;
                $results = ['album' => \in_array($artist.' / '.$title, $albums, true)
                    ? [['strArtist' => $artist, 'strAlbum' => $title, 'strAlbumThumb' => 'https://r2.theaudiodb.com/images/media/album/thumb/'.rawurlencode($title).'.jpg']]
                    : null];
            } else {
                $this->artistSearches[] = $artist;
                $results = ['artists' => \in_array($artist, $artists, true)
                    ? [['strArtist' => $artist, 'strArtistThumb' => 'https://r2.theaudiodb.com/images/media/artist/thumb/'.rawurlencode($artist).'.jpg']]
                    : null];
            }

            return new MockResponse(json_encode($results, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
        });

        self::getContainer()->set(TheAudioDb::class, new TheAudioDb($client, new MockClock(), '123'));
    }
}
