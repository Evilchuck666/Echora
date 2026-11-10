<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use App\Library\LibraryScanner;
use App\Repository\TrackRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * Favourite tracks, albums and artists of each user (docs/decisions/0009).
 */
final class FavouritesTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private User $listener;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->listener = self::createUser();
        $this->client->setDefaultOptions(['auth_bearer' => self::tokenFor($this->listener)]);
        // Scans tests/Fixtures/music (MUSIC_LIBRARY_PATH in .env.test).
        self::getContainer()->get(LibraryScanner::class)->scan(download: false);
    }

    /**
     * @return iterable<string, array{class-string, array<string, string>, string}>
     */
    public static function kinds(): iterable
    {
        yield 'a track' => [Track::class, ['title' => 'Opening'], 'tracks'];
        yield 'an album' => [Album::class, ['title' => 'First Album'], 'albums'];
        yield 'an artist' => [Artist::class, ['name' => 'Test Artist'], 'artists'];
    }

    /**
     * @param class-string          $class
     * @param array<string, string> $criteria
     */
    #[DataProvider('kinds')]
    public function testAFavouriteIsAddedListedAndRemoved(string $class, array $criteria, string $plural): void
    {
        $iri = (string) $this->findIriBy($class, $criteria);

        $this->client->request('PUT', $iri.'/favourite');
        self::assertResponseStatusCodeSame(204);

        $favourites = $this->client->request('GET', '/api/favourites/'.$plural)->toArray();
        self::assertSame(1, $favourites['totalItems']);
        self::assertIsArray($favourites['member']);
        self::assertIsArray($favourites['member'][0]);
        self::assertSame($iri, $favourites['member'][0]['@id']);
        self::assertTrue($favourites['member'][0]['favourite']);
        $this->client->request('GET', $iri);
        self::assertJsonContains(['favourite' => true]);

        $this->client->request('DELETE', $iri.'/favourite');
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/favourites/'.$plural);
        self::assertJsonContains(['totalItems' => 0]);
        $this->client->request('GET', $iri);
        self::assertJsonContains(['favourite' => false]);
    }

    public function testAddingAndRemovingTwiceIsNotAnError(): void
    {
        $iri = (string) $this->findIriBy(Track::class, ['title' => 'Opening']);

        $this->client->request('PUT', $iri.'/favourite');
        $this->client->request('PUT', $iri.'/favourite');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/favourites/tracks');
        self::assertJsonContains(['totalItems' => 1]);

        $this->client->request('DELETE', $iri.'/favourite');
        $this->client->request('DELETE', $iri.'/favourite');
        self::assertResponseStatusCodeSame(204);
    }

    public function testListsSayWhichItemsAreFavourites(): void
    {
        $this->client->request('PUT', $this->findIriBy(Track::class, ['title' => 'Second Song']).'/favourite');

        $tracks = $this->client->request('GET', '/api/tracks')->toArray()['member'];
        self::assertIsArray($tracks);
        $favourites = array_column($tracks, 'favourite', 'title');

        self::assertTrue($favourites['Second Song']);
        self::assertFalse($favourites['Opening']);
    }

    public function testSearchResultsSayWhichItemsAreFavourites(): void
    {
        $this->client->request('PUT', $this->findIriBy(Album::class, ['title' => 'First Album']).'/favourite');

        $this->client->request('GET', '/api/search?q=album');

        self::assertJsonContains(['albums' => [['title' => 'First Album', 'favourite' => true]]]);
    }

    public function testTheNewestFavouritesComeFirst(): void
    {
        foreach (['Opening', 'MP3 Song', 'Second Song'] as $title) {
            $this->client->request('PUT', $this->findIriBy(Track::class, ['title' => $title]).'/favourite');
        }

        $tracks = $this->client->request('GET', '/api/favourites/tracks')->toArray()['member'];
        self::assertIsArray($tracks);

        self::assertSame(['Second Song', 'MP3 Song', 'Opening'], array_column($tracks, 'title'));
    }

    public function testEachUserHasTheirOwnFavourites(): void
    {
        $iri = (string) $this->findIriBy(Track::class, ['title' => 'Opening']);
        $this->client->request('PUT', $iri.'/favourite');
        $other = ['auth_bearer' => self::tokenFor(self::createUser('someone-else'))];

        $this->client->request('GET', '/api/favourites/tracks', $other);
        self::assertJsonContains(['totalItems' => 0]);
        $this->client->request('GET', $iri, $other);
        self::assertJsonContains(['favourite' => false]);

        // Removing it for the other user leaves it for the first one.
        $this->client->request('DELETE', $iri.'/favourite', $other);
        $this->client->request('GET', '/api/favourites/tracks');
        self::assertJsonContains(['totalItems' => 1]);
    }

    public function testAnUnknownItemIsNotFound(): void
    {
        $this->client->request('PUT', '/api/tracks/'.Uuid::v7()->toRfc4122().'/favourite');

        self::assertResponseStatusCodeSame(404);
    }

    public function testFavouritesNeedAJwt(): void
    {
        $iri = (string) $this->findIriBy(Track::class, ['title' => 'Opening']);

        $this->client->request('PUT', $iri.'/favourite', ['auth_bearer' => '']);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/favourites/tracks', ['auth_bearer' => '']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testAFavouriteGoesWithItsTrackWhenAScanRemovesIt(): void
    {
        $iri = (string) $this->findIriBy(Track::class, ['title' => 'Opening']);
        $this->client->request('PUT', $iri.'/favourite');

        // As the scanner does when the file is gone.
        self::getContainer()->get(TrackRepository::class)->deleteByPaths(['Test Artist/2020 - First Album/01 - Opening.flac']);

        $this->client->request('GET', '/api/favourites/tracks');
        self::assertJsonContains(['totalItems' => 0]);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function invalidFavourites(): iterable
    {
        yield 'of nothing' => [false, false];
        yield 'of two things' => [true, true];
    }

    #[DataProvider('invalidFavourites')]
    public function testTheDatabaseRefusesAFavouriteOfNothingOrOfTwoThings(bool $withTrack, bool $withAlbum): void
    {
        $connection = self::getContainer()->get(Connection::class);

        try {
            $connection->insert('favourite', [
                'id' => Uuid::v7()->toRfc4122(),
                'user_id' => $this->listener->getId()->toRfc4122(),
                'track_id' => $withTrack ? $connection->fetchOne('SELECT id FROM track LIMIT 1') : null,
                'album_id' => $withAlbum ? $connection->fetchOne('SELECT id FROM album LIMIT 1') : null,
                'created_at' => '2026-11-10 12:00:00',
            ]);
            self::fail('The CHECK constraint should refuse it.');
        } catch (DriverException $e) {
            self::assertSame('23514', $e->getSQLState(), 'A check violation');
        }
    }
}
