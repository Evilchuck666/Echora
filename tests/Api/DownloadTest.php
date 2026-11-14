<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Library\LibraryScanner;
use App\Library\OpusTranscoder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Downloads to play offline (docs/decisions/0020): manifests of tracks, albums, artists and playlists, and the files
 * they point to, as attachments.
 */
final class DownloadTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->token = self::tokenFor(self::createUser());
        (new Filesystem())->remove(self::getContainer()->getParameter('app.media_dir').'/transcodes');
        self::getContainer()->get(LibraryScanner::class)->scan(download: false);
    }

    public function testAnAlbumManifestListsItsTracksInOrderWithWhereToSaveThem(): void
    {
        $manifest = $this->manifest('albums', $this->albumId('First Album'));

        self::assertSame(['album', 'First Album', 'original', 2, true], [$manifest['kind'], $manifest['name'], $manifest['format'], $manifest['trackCount'], $manifest['totalSizeComplete']]);
        $tracks = self::list($manifest['tracks']);
        self::assertSame(['Opening', 'Second Song'], array_column($tracks, 'title'));
        self::assertSame(['Test Artist/2020 - First Album/01 - Opening.flac', 'Test Artist/2020 - First Album/02 - Second Song.flac'], array_column($tracks, 'savePath'));
        self::assertSame(['flac', 'flac'], array_column($tracks, 'format'));
        self::assertSame(array_sum(array_column($tracks, 'size')), $manifest['totalSize']);
        self::assertStringContainsString('/file?', self::str($tracks[0]['url']));
        self::assertStringContainsString('_hash=', self::str($tracks[0]['url']));
        $covers = self::list($manifest['covers']);
        self::assertCount(1, $covers);
        self::assertSame('Test Artist/2020 - First Album/cover.jpg', $covers[0]['savePath']);
        self::assertGreaterThan(time() + 23 * 3600, strtotime(self::str($manifest['expiresAt'])), 'URLs valid a day.');
    }

    public function testInOpusFlacTracksPointToTheirOpusVersionAndMp3TracksStayAsTheyAre(): void
    {
        $manifest = $this->manifest('artists', $this->artistId('Test Artist'), 'opus');

        $tracks = self::list($manifest['tracks']);
        self::assertSame(['Opening', 'Second Song', 'MP3 Song'], array_column($tracks, 'title'));
        self::assertSame(['opus', 'opus', 'mp3'], array_column($tracks, 'format'));
        self::assertSame([null, null], [$tracks[0]['size'], $tracks[1]['size']], 'Not converted yet: unknown.');
        self::assertFalse($manifest['totalSizeComplete']);
        self::assertSame('Test Artist/2020 - First Album/01 - Opening.opus', $tracks[0]['savePath']);
        self::assertSame('Test Artist/2022 - Second Album/01 - MP3 Song.mp3', $tracks[2]['savePath']);
        self::assertStringContainsString('/file/opus?', self::str($tracks[0]['url']));
        self::assertStringNotContainsString('/opus', self::str($tracks[2]['url']));

        $this->client->request('GET', self::str($tracks[0]['url']));
        self::assertResponseIsSuccessful();
        $converted = self::list($this->manifest('artists', $this->artistId('Test Artist'), 'opus')['tracks']);
        self::assertIsInt($converted[0]['size'], 'Known once converted.');
    }

    public function testAnArtistManifestHasTheirOwnAlbumsAndTheirTracksOnOtherAlbums(): void
    {
        $tracks = self::list($this->manifest('artists', $this->artistId('Guest Artist'))['tracks']);

        self::assertSame(['Guest Track'], array_column($tracks, 'title'));
        // In the folder of its album; tagged disc 2, so its number says which disc.
        self::assertSame('Various Artists/2021 - Compilation/2-01 - Guest Track.flac', $tracks[0]['savePath']);
    }

    public function testAPlaylistManifestHasEachTrackOnceAndTheOrderOfItsItems(): void
    {
        $opening = $this->trackId('Opening');
        $second = $this->trackId('Second Song');
        $playlist = self::str($this->client->request('POST', '/api/playlists', ['auth_bearer' => $this->token, 'json' => ['name' => 'Road trip']])->toArray()['id']);
        $this->client->request('POST', "/api/playlists/$playlist/items", ['auth_bearer' => $this->token, 'json' => ['tracks' => [$second, $opening, $second]]]);

        $manifest = $this->manifest('playlists', $playlist);

        self::assertSame(['playlist', 'Road trip'], [$manifest['kind'], $manifest['name']]);
        self::assertSame([$second, $opening], array_column(self::list($manifest['tracks']), 'id'));
        self::assertSame([$second, $opening, $second], $manifest['items']);

        $this->token = self::tokenFor(self::createUser('someone-else'));
        $this->request("/api/playlists/$playlist/download");
        self::assertResponseStatusCodeSame(404, 'Someone else\'s playlist.');
    }

    public function testASingleTrackHasAManifestToo(): void
    {
        $manifest = $this->manifest('tracks', $this->trackId('Guest Track'));

        self::assertSame(['track', 1], [$manifest['kind'], $manifest['trackCount']]);
        self::assertSame('Guest Artist', self::list($manifest['tracks'])[0]['artist']);
    }

    public function testTheFilesAreAttachmentsWithAReadableNameAndAnswerRanges(): void
    {
        $url = self::str(self::list($this->manifest('tracks', $this->trackId('Opening'))['tracks'])[0]['url']);

        $this->client->request('GET', $url, ['headers' => ['Range' => 'bytes=0-3']]);

        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('content-type', 'audio/flac');
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename="01 - Opening.flac"');

        $opus = self::str(self::list($this->manifest('tracks', $this->trackId('Opening'), 'opus')['tracks'])[0]['url']);
        $this->client->request('GET', $opus);
        self::assertResponseHeaderSame('content-type', OpusTranscoder::MIME_TYPE);
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename="01 - Opening.opus"');
    }

    public function testFilesNeedASignatureOrAJwtAndMp3TracksHaveNoOpusFile(): void
    {
        $url = self::str(self::list($this->manifest('tracks', $this->trackId('Opening'))['tracks'])[0]['url']);

        $this->client->request('GET', strtok($url, '?') ?: '');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', strtok($url, '?') ?: '', ['auth_bearer' => $this->token]);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/tracks/'.$this->trackId('MP3 Song').'/file/opus', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testManifestsNeedAJwtAKnownItemAndAKnownFormat(): void
    {
        $this->client->request('GET', '/api/albums/'.$this->albumId('First Album').'/download');
        self::assertResponseStatusCodeSame(401);

        $this->request('/api/albums/01a1c051-0000-7000-8000-000000000000/download');
        self::assertResponseStatusCodeSame(404);

        $this->request('/api/albums/'.$this->albumId('First Album').'/download?format=wav');
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return array<mixed>
     */
    private function manifest(string $kind, string $id, ?string $format = null): array
    {
        $response = $this->request("/api/$kind/$id/download".(null === $format ? '' : "?format=$format"));
        self::assertResponseIsSuccessful();

        return $response->toArray();
    }

    private function request(string $url): ResponseInterface
    {
        return $this->client->request('GET', $url, ['auth_bearer' => $this->token]);
    }

    private function trackId(string $title): string
    {
        $track = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(Track::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Track::class, $track);

        return $track->getId()->toRfc4122();
    }

    private function albumId(string $title): string
    {
        $album = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(Album::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Album::class, $album);

        return $album->getId()->toRfc4122();
    }

    private function artistId(string $name): string
    {
        $artist = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(Artist::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Artist::class, $artist);

        return $artist->getId()->toRfc4122();
    }

    /**
     * @return list<array<mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values(array_filter($value, \is_array(...)));
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
