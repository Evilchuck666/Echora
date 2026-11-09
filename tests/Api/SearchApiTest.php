<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Library\AudioMetadata;
use App\Library\LibraryScanner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

final class SearchApiTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->setDefaultOptions(['auth_bearer' => self::tokenFor(self::createUser())]);
        // Scans tests/Fixtures/music (MUSIC_LIBRARY_PATH in .env.test): Test Artist, Various Artists...
        self::getContainer()->get(LibraryScanner::class)->scan();
        // ...plus an artist with an accent, and titles that rank differently for "spades".
        self::addAlbum('Motörhead', 'Ace of Spades', ['Ace of Spades', 'Spades Are Wild', 'Spades', 'Love Me Like a Reptile']);
    }

    public function testItFindsArtistsAlbumsAndTracksAtOnce(): void
    {
        $this->client->request('GET', '/api/search?q=test');

        self::assertResponseIsSuccessful();
        self::assertJsonContains([
            '@type' => 'Search',
            'query' => 'test',
            'totalArtists' => 1,
            'artists' => [['name' => 'Test Artist']],
            // Through their artist.
            'totalAlbums' => 2,
            'albums' => [['title' => 'First Album'], ['title' => 'Second Album']],
            'totalTracks' => 3,
        ]);
    }

    #[DataProvider('accentAndCaseVariants')]
    public function testCaseAndAccentsAreIgnored(string $query): void
    {
        $this->client->request('GET', '/api/search?q='.rawurlencode($query));

        self::assertJsonContains(['totalArtists' => 1, 'artists' => [['name' => 'Motörhead']]]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function accentAndCaseVariants(): iterable
    {
        yield 'without the accent' => ['motorhead'];
        yield 'upper case' => ['MOTÖRHEAD'];
        yield 'part of the name' => ['torh'];
    }

    public function testEveryWordHasToMatchInAnyOrder(): void
    {
        $this->client->request('GET', '/api/search?q='.rawurlencode('spades ace'));

        self::assertJsonContains(['totalArtists' => 0, 'totalAlbums' => 1, 'albums' => [['title' => 'Ace of Spades']]]);
        // Every track of the album: the words are in the title, the album title or both.
        self::assertJsonContains(['totalTracks' => 4]);

        $this->client->request('GET', '/api/search?q='.rawurlencode('ace jazz'));

        self::assertJsonContains(['totalArtists' => 0, 'totalAlbums' => 0, 'totalTracks' => 0]);
    }

    public function testResultsAreRankedByHowWellTheirOwnNameMatches(): void
    {
        $tracks = $this->client->request('GET', '/api/search?q=spades')->toArray()['tracks'];

        self::assertIsArray($tracks);
        self::assertSame(
            [
                'Spades', // the same
                'Spades Are Wild', // starts with it
                'Ace of Spades', // contains it
                'Love Me Like a Reptile', // only its album does
            ],
            array_column($tracks, 'title'),
        );
    }

    public function testTracksAreFoundThroughTheirArtist(): void
    {
        $this->client->request('GET', '/api/search?q='.rawurlencode('guest'));

        self::assertJsonContains([
            'artists' => [['name' => 'Guest Artist']],
            'tracks' => [['title' => 'Guest Track', 'artist' => ['name' => 'Guest Artist'], 'album' => ['title' => 'Compilation']]],
        ]);
    }

    public function testTheLimitCapsEachListButNotTheTotals(): void
    {
        $body = $this->client->request('GET', '/api/search?q=spades&limit=2')->toArray();

        self::assertIsArray($body['tracks']);
        self::assertCount(2, $body['tracks']);
        self::assertSame(4, $body['totalTracks']);
    }

    #[DataProvider('wildcards')]
    public function testWildcardsAreMatchedLiterally(string $query): void
    {
        $this->client->request('GET', '/api/search?q='.rawurlencode($query));

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['totalArtists' => 0, 'totalAlbums' => 0, 'totalTracks' => 0]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function wildcards(): iterable
    {
        yield 'percent' => ['%'];
        yield 'underscore' => ['_'];
        yield 'backslash' => ['\\'];
    }

    public function testResultsCarrySignedUrlsButNotLyrics(): void
    {
        $body = $this->client->request('GET', '/api/search?q=spades')->toArray();

        self::assertIsArray($body['tracks']);
        self::assertIsArray($body['tracks'][0]);
        self::assertIsString($body['tracks'][0]['streamUrl']);
        self::assertArrayHasKey('hasLyrics', $body['tracks'][0]);
        self::assertArrayNotHasKey('lyrics', $body['tracks'][0]);

        // First Album has a folder.jpg. (A null coverUrl is left out, as everywhere in the API.)
        $albums = $this->client->request('GET', '/api/search?q='.rawurlencode('first album'))->toArray()['albums'];
        self::assertIsArray($albums);
        self::assertIsArray($albums[0]);
        self::assertIsString($albums[0]['coverUrl']);
    }

    #[DataProvider('invalidParameters')]
    public function testInvalidParametersAreRejected(string $queryString): void
    {
        $this->client->request('GET', '/api/search'.$queryString);

        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidParameters(): iterable
    {
        yield 'no query' => [''];
        yield 'empty query' => ['?q='];
        yield 'blank query' => ['?q=%20%20'];
        yield 'query too long' => ['?q='.str_repeat('a', 101)];
        yield 'limit zero' => ['?q=test&limit=0'];
        yield 'limit too high' => ['?q=test&limit=51'];
        yield 'limit not a number' => ['?q=test&limit=many'];
        yield 'limit not whole' => ['?q=test&limit=1.5'];
    }

    /**
     * @param list<string> $trackTitles
     */
    private static function addAlbum(string $artistName, string $albumTitle, array $trackTitles): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $artist = new Artist($artistName);
        $album = new Album($artist, $albumTitle, 1980);
        $entityManager->persist($artist);
        $entityManager->persist($album);

        foreach ($trackTitles as $number => $title) {
            $metadata = new AudioMetadata($title, $artistName, $artistName, $albumTitle, 1980, $number + 1, 1, null, null, 180000, 'flac', 'audio/flac', 1000, 0);
            $entityManager->persist(new Track(\sprintf('%s/%s/%02d.flac', $artistName, $albumTitle, $number + 1), $album, $artist, $metadata));
        }

        $entityManager->flush();
    }
}
