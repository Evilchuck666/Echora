<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Doctrine\SqlValue;
use App\Entity\Album;
use App\Entity\Playlist;
use App\Entity\Track;
use App\Entity\User;
use App\Library\LibraryScanner;
use App\Playlists\PlaylistEditor;
use App\Repository\TrackRepository;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Playlists of each user: /api/playlists, /api/playlists/{id}/items and /api/playlist_items/{id}.
 */
final class PlaylistsTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private User $user;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->user = self::createUser();
        $this->token = self::tokenFor($this->user);
        self::getContainer()->get(LibraryScanner::class)->scan(download: false);
    }

    public function testAPlaylistIsCreatedRenamedAndDeleted(): void
    {
        $created = $this->request('POST', '/api/playlists', ['name' => 'Road trip']);
        self::assertResponseStatusCodeSame(201);
        $playlist = $created->toArray();
        self::assertSame('Road trip', $playlist['name']);
        self::assertSame(0, $playlist['trackCount']);
        self::assertSame(0, $playlist['durationMs']);
        self::assertSame([], $playlist['covers']);
        self::assertSame($playlist['createdAt'], $playlist['updatedAt']);
        self::assertIsString($playlist['@id']);
        $iri = $playlist['@id'];

        self::assertSame([$iri], $this->playlistIris());

        $renamed = $this->request('PATCH', $iri, ['name' => 'Road trip 2']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Road trip 2', $renamed->toArray()['name']);
        self::assertSame('Road trip 2', $this->request('GET', $iri)->toArray()['name']);

        $this->request('DELETE', $iri);
        self::assertResponseStatusCodeSame(204);
        $this->request('GET', $iri);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidNames(): iterable
    {
        yield 'empty' => [''];
        yield 'only spaces' => ['   '];
        yield 'leading space' => [' Road trip'];
        yield 'trailing space' => ['Road trip '];
        yield 'trailing new line' => ["Road trip\n"];
        yield '101 characters' => [str_repeat('é', 101)];
    }

    #[DataProvider('invalidNames')]
    public function testANameHasOneToAHundredCharactersWithoutSpacesAround(string $name): void
    {
        $this->request('POST', '/api/playlists', ['name' => $name]);
        self::assertResponseStatusCodeSame(422);

        $iri = $this->createPlaylist();
        $this->request('PATCH', $iri, ['name' => $name]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNamesNeedNotBeUniqueAndMayHaveAHundredCharacters(): void
    {
        $this->createPlaylist('Rock');
        $this->createPlaylist('Rock');
        $this->createPlaylist(str_repeat('é', 100));

        self::assertResponseStatusCodeSame(201);
        self::assertCount(3, $this->playlistIris());
    }

    public function testANewPlaylistComesFirstAndTheOthersMoveDown(): void
    {
        $first = $this->createPlaylist('First');
        $second = $this->createPlaylist('Second');

        $created = $this->request('POST', '/api/playlists', ['name' => 'Third']);
        self::assertSame(0, $created->toArray()['position']);
        $third = self::str($created->toArray()['@id']);

        self::assertSame([$third, $second, $first], $this->playlistIris());
        self::assertSame([0, 1, 2], $this->playlistPositions());
        self::assertSame(2, $this->request('GET', $first)->toArray()['position']);
    }

    public function testAPlaylistMovesUpAndDownAndTheOthersShift(): void
    {
        [$c, $b, $a] = [$this->createPlaylist('A'), $this->createPlaylist('B'), $this->createPlaylist('C')];
        [$a, $b, $c] = [$c, $b, $a]; // Listed C, B, A: the newest first.
        self::assertSame([$c, $b, $a], $this->playlistIris());

        $moved = $this->request('PATCH', $c, ['position' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $moved->toArray()['position']);
        self::assertSame([$b, $a, $c], $this->playlistIris());

        self::assertSame(0, $this->request('PATCH', $c, ['position' => 0])->toArray()['position']);
        self::assertSame([$c, $b, $a], $this->playlistIris());

        self::assertSame(2, $this->request('PATCH', $b, ['position' => 99])->toArray()['position'], 'Beyond the end: to the end.');
        self::assertSame([$c, $a, $b], $this->playlistIris());

        $this->request('PATCH', $a, ['position' => -1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([$c, $a, $b], $this->playlistIris());

        $both = $this->request('PATCH', $b, ['name' => 'B, renamed', 'position' => 0])->toArray();
        self::assertSame(['B, renamed', 0], [$both['name'], $both['position']]);
        self::assertSame([$b, $c, $a], $this->playlistIris());
        self::assertSame([0, 1, 2], $this->playlistPositions());
    }

    public function testPositionsStayConsecutiveWhenAPlaylistIsDeleted(): void
    {
        $first = $this->createPlaylist('First');
        $second = $this->createPlaylist('Second');
        $third = $this->createPlaylist('Third');

        $this->request('DELETE', $second);

        self::assertSame([$third, $first], $this->playlistIris());
        self::assertSame([0, 1], $this->playlistPositions());
        self::assertSame(1, $this->request('GET', $first)->toArray()['position']);
    }

    public function testMovingAPlaylistIsNotAChangeToIt(): void
    {
        $first = $this->createPlaylist('First');
        $second = $this->createPlaylist('Second');
        $this->connection()->executeStatement("UPDATE playlist SET updated_at = updated_at - INTERVAL '1 day'");
        $before = $this->request('GET', $first)->toArray()['updatedAt'];

        $this->request('PATCH', $first, ['position' => 0]);
        $this->request('PATCH', $first, ['name' => 'First']);
        self::assertSame($before, $this->request('GET', $first)->toArray()['updatedAt'], 'Moved, and given the same name: unchanged.');

        $this->addTracks($second, [$this->trackId('Opening')]);
        self::assertSame([$first, $second], $this->playlistIris(), 'A change to a playlist doesn\'t move it.');

        $this->request('PATCH', $first, ['name' => 'First, renamed']);
        self::assertGreaterThan($before, $this->request('GET', $first)->toArray()['updatedAt']);
    }

    public function testEachUserHasTheirOwnOrder(): void
    {
        $mine = [$this->createPlaylist('Mine 1'), $this->createPlaylist('Mine 2')];
        $myToken = $this->token;
        $this->token = self::tokenFor(self::createUser('someone-else'));
        $theirs = [$this->createPlaylist('Theirs 1'), $this->createPlaylist('Theirs 2'), $this->createPlaylist('Theirs 3')];
        $this->request('PATCH', $theirs[2], ['position' => 2]);
        $this->request('PATCH', $mine[0], ['position' => 0]);
        self::assertResponseStatusCodeSame(404, 'Nobody moves someone else\'s playlist.');
        self::assertSame([$theirs[1], $theirs[0], $theirs[2]], $this->playlistIris());

        $this->token = $myToken;
        self::assertSame([$mine[1], $mine[0]], $this->playlistIris());
        self::assertSame([0, 1], $this->playlistPositions());
    }

    public function testEachUserOnlySeesTheirOwnPlaylists(): void
    {
        $iri = $this->createPlaylist();
        $this->addTracks($iri, [$this->trackId('Opening')]);
        $item = $this->itemIris($iri)[0];
        $mine = $this->token;
        $this->token = self::tokenFor(self::createUser('someone-else'));

        self::assertSame([], $this->playlistIris());
        $this->request('GET', $iri);
        self::assertResponseStatusCodeSame(404);
        $this->request('PATCH', $iri, ['name' => 'Mine now']);
        self::assertResponseStatusCodeSame(404);
        $this->request('GET', $iri.'/items');
        self::assertResponseStatusCodeSame(404);
        $this->request('POST', $iri.'/items', ['tracks' => [$this->trackId('Opening')]]);
        self::assertResponseStatusCodeSame(404);
        $this->request('PATCH', $item, ['position' => 0]);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', $item);
        self::assertResponseStatusCodeSame(404);
        $this->request('DELETE', $iri);
        self::assertResponseStatusCodeSame(404);

        $this->token = $mine;
        self::assertSame('My playlist', $this->request('GET', $iri)->toArray()['name']);
        self::assertSame(1, $this->request('GET', $iri)->toArray()['trackCount']);
    }

    public function testEverythingNeedsAJwt(): void
    {
        $this->client->request('GET', '/api/playlists');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/playlists', ['json' => ['name' => 'Anonymous']]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testTracksAreAddedAtTheEndInOrderDuplicatesIncluded(): void
    {
        $iri = $this->createPlaylist();
        $opening = $this->trackId('Opening');
        $second = $this->trackId('Second Song');
        $guest = $this->trackId('Guest Track');

        $response = $this->request('POST', $iri.'/items', ['tracks' => [$second, $opening, '/api/tracks/'.$second]]);
        self::assertResponseStatusCodeSame(200);
        $playlist = $response->toArray();
        self::assertSame($iri, $playlist['@id']);
        self::assertSame(3, $playlist['trackCount']);

        $this->addTracks($iri, [$guest]);

        $items = $this->items($iri);
        self::assertSame([0, 1, 2, 3], array_column($items, 'position'));
        self::assertSame([$second, $opening, $second, $guest], array_map(static fn (array $item): string => self::str(self::arr($item['track'])['id']), $items));
        self::assertCount(4, array_unique(array_map(self::str(...), array_column($items, 'id'))), 'Each appearance is an item of its own.');
    }

    public function testItemsComeWithTheirWholeTrackLikeTheTrackList(): void
    {
        $iri = $this->createPlaylist();
        $this->addTracks($iri, [$this->trackId('Opening')]);
        $this->client->request('PUT', '/api/tracks/'.$this->trackId('Opening').'/favourite', ['auth_bearer' => $this->token]);

        $item = $this->items($iri)[0];

        self::assertIsString($item['@id']);
        self::assertStringStartsWith('/api/playlist_items/', $item['@id']);
        self::assertSame(0, $item['position']);
        self::assertIsString($item['addedAt']);
        $track = self::arr($item['track']);
        self::assertSame('Opening', $track['title']);
        self::assertStringContainsString('_hash=', self::str($track['streamUrl']));
        self::assertStringContainsString('/stream/opus?', self::str($track['opusStreamUrl']));
        self::assertTrue($track['favourite']);
        self::assertStringContainsString('/cover?', self::str(self::arr($track['album'])['coverUrl']));
        self::assertArrayNotHasKey('path', $track);
        self::assertArrayNotHasKey('sortKey', $item);
    }

    public function testItemsArePaginatedThirtyAtATime(): void
    {
        $iri = $this->createPlaylist();
        $this->addTracks($iri, array_fill(0, 35, $this->trackId('Opening')));

        $firstPage = $this->request('GET', $iri.'/items')->toArray();
        $secondPage = $this->request('GET', $iri.'/items?page=2')->toArray();

        self::assertSame(35, $firstPage['totalItems']);
        self::assertSame(range(0, 29), array_column(self::arr($firstPage['member']), 'position'));
        self::assertSame(range(30, 34), array_column(self::arr($secondPage['member']), 'position'));
    }

    public function testUnknownTracksAddNothing(): void
    {
        $iri = $this->createPlaylist();
        $this->addTracks($iri, [$this->trackId('Opening')]);

        $this->request('POST', $iri.'/items', ['tracks' => [$this->trackId('Second Song'), '01a1c051-0000-7000-8000-000000000000']]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('01a1c051-0000-7000-8000-000000000000', (string) $this->client->getResponse()?->getContent(false));

        $this->request('POST', $iri.'/items', ['tracks' => ['/api/albums/01a1c051-0000-7000-8000-000000000000']]);
        self::assertResponseStatusCodeSame(422);
        $this->request('POST', $iri.'/items', ['tracks' => []]);
        self::assertResponseStatusCodeSame(422);

        self::assertSame(1, $this->request('GET', $iri)->toArray()['trackCount']);
    }

    public function testAPlaylistHoldsAtMostTenThousandItems(): void
    {
        $iri = $this->createPlaylist();
        $opening = $this->trackId('Opening');
        self::getContainer()->get(PlaylistEditor::class)->add($this->user, $this->idOf($iri), array_fill(0, Playlist::MAX_ITEMS - 1, $opening));

        $this->request('POST', $iri.'/items', ['tracks' => [$opening, $opening]]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('at most 10000 items', (string) $this->client->getResponse()?->getContent(false));

        self::assertSame(Playlist::MAX_ITEMS, $this->addTracks($iri, [$opening])['trackCount']);
    }

    public function testItemsMoveDownAndUpAndTheOthersShift(): void
    {
        $iri = $this->createPlaylist();
        $titles = ['Opening', 'Second Song', 'Guest Track', 'MP3 Song'];
        $this->addTracks($iri, array_map($this->trackId(...), $titles));
        [$a, $b, $c, $d] = $this->itemIris($iri);

        $moved = $this->request('PATCH', $a, ['position' => 2]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(2, $moved->toArray()['position']);
        self::assertSame('Opening', self::arr($moved->toArray()['track'])['title']);
        self::assertSame([$b, $c, $a, $d], $this->itemIris($iri));

        $this->request('PATCH', $d, ['position' => 0]);
        self::assertSame([$d, $b, $c, $a], $this->itemIris($iri));

        self::assertSame(3, $this->request('PATCH', $b, ['position' => 99])->toArray()['position'], 'Beyond the end: to the end.');
        self::assertSame([$d, $c, $a, $b], $this->itemIris($iri));

        $this->request('PATCH', $c, ['position' => -1]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([$d, $c, $a, $b], $this->itemIris($iri));
        self::assertSame([0, 1, 2, 3], array_column($this->items($iri), 'position'));
    }

    public function testRemovingAnItemRemovesThatAppearanceOnly(): void
    {
        $iri = $this->createPlaylist();
        $opening = $this->trackId('Opening');
        $this->addTracks($iri, [$opening, $this->trackId('Second Song'), $opening]);
        [$first, $second, $third] = $this->itemIris($iri);

        $this->request('DELETE', $first);
        self::assertResponseStatusCodeSame(204);

        self::assertSame([$second, $third], $this->itemIris($iri));
        self::assertSame([0, 1], array_column($this->items($iri), 'position'));
        self::assertSame(2, $this->request('GET', $iri)->toArray()['trackCount']);
        $this->request('DELETE', $first);
        self::assertResponseStatusCodeSame(404);
    }

    public function testATrackRemovedByAScanLeavesThePlaylistsInOrder(): void
    {
        $library = sys_get_temp_dir().'/echora-library-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(__DIR__.'/../Fixtures/music', $library);
        try {
            $scanner = self::getContainer()->get(LibraryScanner::class);
            $scanner->scan($library, download: false);
            $iri = $this->createPlaylist();
            $second = $this->trackId('Second Song');
            $this->addTracks($iri, [$this->trackId('Opening'), $second, $this->trackId('Guest Track'), $second, $this->trackId('MP3 Song')]);
            $items = $this->itemIris($iri);
            $this->connection()->executeStatement("UPDATE playlist SET updated_at = updated_at - INTERVAL '1 day'");
            $before = $this->request('GET', $iri)->toArray()['updatedAt'];

            unlink($library.'/Test Artist/2020 - First Album/02 - Second Song.flac');
            $scanner->scan($library, download: false);

            self::assertSame([$items[0], $items[2], $items[4]], $this->itemIris($iri));
            self::assertSame([0, 1, 2], array_column($this->items($iri), 'position'));
            $playlist = $this->request('GET', $iri)->toArray();
            self::assertSame(3, $playlist['trackCount']);
            self::assertGreaterThan($before, $playlist['updatedAt'], 'Losing tracks changes the playlist.');

            $this->request('PATCH', $items[4], ['position' => 0]);
            self::assertSame([$items[4], $items[0], $items[2]], $this->itemIris($iri), 'Moving still works on the new positions.');
        } finally {
            (new Filesystem())->remove($library);
        }
    }

    public function testThePlaylistsOfAUserGoWithTheUser(): void
    {
        $this->addTracks($this->createPlaylist(), [$this->trackId('Opening')]);
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $user = $entityManager->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $entityManager->remove($user);
        $entityManager->flush();

        self::assertSame(0, SqlValue::int($this->connection()->fetchOne('SELECT COUNT(*) FROM playlist')));
        self::assertSame(0, SqlValue::int($this->connection()->fetchOne('SELECT COUNT(*) FROM playlist_item')));
    }

    public function testDurationAndCoversSumUpTheTracks(): void
    {
        $iri = $this->createPlaylist();
        $tracks = self::getContainer()->get(TrackRepository::class);
        $titles = ['Guest Track', 'Opening', 'MP3 Song', 'Second Song', 'Opening'];
        $this->addTracks($iri, array_map($this->trackId(...), $titles));

        $playlist = $this->request('GET', $iri)->toArray();

        $expected = 0;
        foreach ($titles as $title) {
            $expected += $tracks->findOneBy(['title' => $title])?->getDurationMs() ?? 0;
        }
        self::assertGreaterThan(0, $expected);
        self::assertSame($expected, $playlist['durationMs']);
        // The compilation has no cover: First Album, then Second Album, each once.
        $covers = array_map(self::str(...), self::arr($playlist['covers']));
        self::assertCount(2, $covers);
        self::assertStringContainsString('/api/albums/'.$this->albumId('First Album').'/cover?', $covers[0]);
        self::assertStringContainsString('/api/albums/'.$this->albumId('Second Album').'/cover?', $covers[1]);
        unset($playlist['@context']);
        self::assertSame($playlist, self::arr($this->request('GET', '/api/playlists')->toArray()['member'])[0], 'Lists show the same.');
    }

    public function testAPageOfPlaylistsCostsTheSameNumberOfQueriesWhateverTheirNumber(): void
    {
        $opening = $this->trackId('Opening');
        $second = $this->trackId('Second Song');
        $this->addTracks($this->createPlaylist(), [$opening, $second]);
        $few = $this->countQueries(fn () => $this->request('GET', '/api/playlists'));

        foreach (range(1, 6) as $i) {
            $this->addTracks($this->createPlaylist("Playlist $i"), [$second, $opening, $this->trackId('MP3 Song')]);
        }
        $many = $this->countQueries(fn () => $this->request('GET', '/api/playlists'));

        self::assertCount(7, $this->playlistIris());
        self::assertGreaterThan(0, $few);
        self::assertSame($few, $many);
    }

    public function testAPageOfItemsCostsTheSameNumberOfQueriesWhateverTheirNumber(): void
    {
        $iri = $this->createPlaylist();
        $this->addTracks($iri, [$this->trackId('Opening')]);
        $few = $this->countQueries(fn () => $this->request('GET', $iri.'/items'));

        $this->addTracks($iri, array_map($this->trackId(...), ['Second Song', 'Guest Track', 'MP3 Song', 'Opening', 'Second Song']));
        $many = $this->countQueries(fn () => $this->request('GET', $iri.'/items'));

        self::assertGreaterThan(0, $few);
        self::assertSame($few, $many);
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function request(string $method, string $url, ?array $json = null): ResponseInterface
    {
        $options = ['auth_bearer' => $this->token, 'headers' => ['Accept' => 'application/ld+json']];
        if (null !== $json) {
            $options['json'] = $json;
            if ('PATCH' === $method) {
                $options['headers']['Content-Type'] = 'application/merge-patch+json';
            }
        }

        return $this->client->request($method, $url, $options);
    }

    private function createPlaylist(string $name = 'My playlist'): string
    {
        $iri = $this->request('POST', '/api/playlists', ['name' => $name])->toArray()['@id'];
        self::assertIsString($iri);

        return $iri;
    }

    /**
     * @param list<string> $trackIds
     *
     * @return array<mixed> The playlist
     */
    private function addTracks(string $playlist, array $trackIds): array
    {
        $response = $this->request('POST', $playlist.'/items', ['tracks' => $trackIds]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return $response->toArray();
    }

    /**
     * @return list<string>
     */
    private function playlistIris(): array
    {
        return array_map(self::str(...), array_column(self::arr($this->request('GET', '/api/playlists')->toArray()['member']), '@id'));
    }

    /**
     * @return list<mixed>
     */
    private function playlistPositions(): array
    {
        return array_column(self::arr($this->request('GET', '/api/playlists')->toArray()['member']), 'position');
    }

    /**
     * @return list<array<mixed>> Every item, all pages
     */
    private function items(string $playlist): array
    {
        $items = [];
        for ($page = 1;; ++$page) {
            $member = self::arr($this->request('GET', "$playlist/items?page=$page")->toArray()['member']);
            if ([] === $member) {
                return $items;
            }
            foreach ($member as $item) {
                $items[] = self::arr($item);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function itemIris(string $playlist): array
    {
        return array_map(self::str(...), array_column($this->items($playlist), '@id'));
    }

    private function trackId(string $title): string
    {
        $track = self::getContainer()->get(TrackRepository::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Track::class, $track);

        return $track->getId()->toRfc4122();
    }

    private function albumId(string $title): string
    {
        $album = self::getContainer()->get('doctrine.orm.entity_manager')->getRepository(Album::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Album::class, $album);

        return $album->getId()->toRfc4122();
    }

    private function idOf(string $iri): string
    {
        return substr($iri, (int) strrpos($iri, '/') + 1);
    }

    /**
     * @return array<mixed>
     */
    private static function arr(mixed $value): array
    {
        self::assertIsArray($value);

        return $value;
    }

    private static function str(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    /**
     * Number of SQL queries one request makes.
     */
    private function countQueries(callable $request): int
    {
        $request();
        $queries = self::getContainer()->get('doctrine.debug_data_holder');

        return \count($queries->getData()['default'] ?? []);
    }
}
