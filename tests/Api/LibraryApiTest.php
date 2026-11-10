<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Library\LibraryScanner;

final class LibraryApiTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setDefaultOptions(['auth_bearer' => self::tokenFor(self::createUser())]);
        // Scans tests/Fixtures/music (MUSIC_LIBRARY_PATH in .env.test).
        self::getContainer()->get(LibraryScanner::class)->scan();
    }

    public function testArtistsAreListedByName(): void
    {
        $this->client->request('GET', '/api/artists');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'totalItems' => 4,
            'member' => [
                ['name' => 'Guest Artist'],
                ['name' => 'Test Artist'],
                ['name' => 'Unknown Artist'],
                ['name' => 'Various Artists'],
            ],
        ]);
    }

    public function testArtistsCanBeSearchedByName(): void
    {
        $this->client->request('GET', '/api/artists?name=various');

        self::assertJsonContains(['totalItems' => 1, 'member' => [['name' => 'Various Artists']]]);
    }

    public function testTheAlbumsOfAnArtistAreListedChronologically(): void
    {
        $artistIri = $this->findIriBy(Artist::class, ['name' => 'Test Artist']);

        $this->client->request('GET', '/api/albums?artist='.$artistIri);

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'totalItems' => 2,
            'member' => [
                ['title' => 'First Album', 'year' => 2020, 'artist' => ['@id' => $artistIri, 'name' => 'Test Artist']],
                ['title' => 'Second Album', 'year' => 2022],
            ],
        ]);
    }

    public function testTheTracksOfAnAlbumAreListedInOrder(): void
    {
        $albumIri = $this->findIriBy(Album::class, ['title' => 'First Album']);

        $this->client->request('GET', '/api/tracks?album='.$albumIri);

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'totalItems' => 2,
            'member' => [
                [
                    'title' => 'Opening',
                    'trackNumber' => 1,
                    'discNumber' => 1,
                    'durationMs' => 1000,
                    'genre' => 'Heavy Metal',
                    'format' => 'flac',
                    'mimeType' => 'audio/flac',
                    'album' => ['title' => 'First Album', 'year' => 2020],
                    'artist' => ['name' => 'Test Artist'],
                ],
                ['title' => 'Second Song', 'trackNumber' => 2],
            ],
        ]);
    }

    public function testEveryTrackOfAnArtistsAlbumsIsListedAlbumAfterAlbum(): void
    {
        $artistIri = $this->findIriBy(Artist::class, ['name' => 'Test Artist']);

        $this->client->request('GET', '/api/tracks?albumArtist='.$artistIri);

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            'totalItems' => 3,
            'member' => [
                ['title' => 'Opening', 'album' => ['title' => 'First Album']],
                ['title' => 'Second Song', 'album' => ['title' => 'First Album']],
                // Track 1 of the later album comes after track 2 of the earlier one.
                ['title' => 'MP3 Song', 'album' => ['title' => 'Second Album']],
            ],
        ]);
    }

    public function testTheAlbumArtistFilterLeavesOutAppearancesOnOtherArtistsAlbums(): void
    {
        $guestIri = $this->findIriBy(Artist::class, ['name' => 'Guest Artist']);

        $this->client->request('GET', '/api/tracks?albumArtist='.$guestIri);
        self::assertJsonContains(['totalItems' => 0]);

        // The track artist filter does find them.
        $this->client->request('GET', '/api/tracks?artist='.$guestIri);
        self::assertJsonContains(['totalItems' => 1, 'member' => [['title' => 'Guest Track']]]);
    }

    public function testAlbumsComeWithASignedCoverUrlWhenTheyHaveACover(): void
    {
        $albums = $this->client->request('GET', '/api/albums?title=album')->toArray()['member'];
        self::assertIsArray($albums);
        $covers = array_column($albums, 'coverUrl', 'title');

        self::assertIsString($covers['First Album']);
        self::assertMatchesRegularExpression('#/api/albums/[0-9a-f-]+/cover\?.*_hash=#', $covers['First Album']);
        self::assertNull($covers['Unknown Album']);
    }

    public function testListsSayWhetherATrackHasLyricsWithoutIncludingThem(): void
    {
        $tracks = $this->client->request('GET', '/api/tracks?title=o')->toArray()['member'];
        self::assertIsArray($tracks);

        foreach ($tracks as $track) {
            self::assertIsArray($track);
            self::assertArrayNotHasKey('lyrics', $track);
        }
        $hasLyrics = array_column($tracks, 'hasLyrics', 'title');
        self::assertTrue($hasLyrics['Opening']);
        self::assertFalse($hasLyrics['Second Song']);
    }

    public function testASingleTrackIncludesItsLyricsAndTheCoverOfItsAlbum(): void
    {
        $iri = $this->findIriBy(Track::class, ['title' => 'Opening']);
        self::assertNotNull($iri);

        $this->client->request('GET', $iri);

        self::assertJsonContains([
            'hasLyrics' => true,
            'lyrics' => "First line of the opening\nSecond line of the opening",
            'album' => ['title' => 'First Album'],
        ]);
        $track = $this->client->getResponse()?->toArray();
        self::assertIsArray($track);
        $album = $track['album'] ?? null;
        self::assertIsArray($album);
        self::assertIsString($album['coverUrl']);
    }

    public function testTracksNeverExposeWhereTheFileLivesOnTheServer(): void
    {
        $response = $this->client->request('GET', '/api/tracks');

        self::assertResponseIsSuccessful();
        $json = $response->getContent();
        self::assertStringNotContainsString('"path"', $json);
        self::assertStringNotContainsString('fileModifiedAt', $json);
        self::assertStringNotContainsString('Fixtures', $json);
    }

    public function testPlainJsonIsAvailableForClientsThatDoNotSpeakJsonLd(): void
    {
        $response = $this->client->request('GET', '/api/artists', ['headers' => ['Accept' => 'application/json']]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');
        self::assertCount(4, $response->toArray());
    }

    public function testTheStreamingEndpointIsDocumentedInOpenApi(): void
    {
        $spec = $this->client->request('GET', '/api/docs', ['headers' => ['Accept' => 'application/vnd.openapi+json']])->toArray();

        self::assertIsArray($spec['paths']);
        self::assertArrayHasKey('/api/tracks/{id}/stream', $spec['paths']);
        self::assertArrayHasKey('/api/tracks', $spec['paths']);
    }
}
