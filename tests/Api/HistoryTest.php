<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Doctrine\SqlValue;
use App\Entity\Track;
use App\Entity\User;
use App\Library\LibraryScanner;
use App\Repository\TrackRepository;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The listening history of each user (docs/decisions/0016): plays recorded by POST /api/tracks/{id}/plays, read as
 * recently played and most played tracks.
 */
final class HistoryTest extends ApiTestCase
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

    public function testPlaysAreRecordedAndTheRecentlyPlayedTracksComeLatestFirst(): void
    {
        $this->play('Opening', '-3 hours');
        $this->play('Second Song', '-2 hours');
        $this->play('Opening', '-1 hour');
        self::assertResponseStatusCodeSame(204);

        $recent = $this->list('/api/me/recent-tracks');

        self::assertSame(['Opening', 'Second Song'], $this->titles($recent), 'Each track once, the latest played first.');
        self::assertSame([2, 1], array_column($recent, 'playCount'));
        self::assertIsString($recent[0]['lastPlayedAt']);
        self::assertSame((new \DateTimeImmutable('-1 hour'))->format('Y-m-d\TH'), substr($recent[0]['lastPlayedAt'], 0, 13));
    }

    public function testTracksComeWholeLikeTheTrackList(): void
    {
        $this->play('Opening');
        $this->client->request('PUT', '/api/tracks/'.$this->trackId('Opening').'/favourite', ['auth_bearer' => $this->token]);

        $track = $this->list('/api/me/recent-tracks')[0]['track'];

        self::assertIsArray($track);
        self::assertSame('Opening', $track['title']);
        self::assertIsString($track['streamUrl']);
        self::assertStringContainsString('_hash=', $track['streamUrl']);
        self::assertTrue($track['favourite']);
        self::assertIsArray($track['album']);
        self::assertArrayHasKey('coverUrl', $track['album']);
        self::assertArrayNotHasKey('path', $track);
    }

    public function testTheMostPlayedTracksComeFirstOverThePeriodAskedFor(): void
    {
        foreach (['-40 days', '-39 days', '-38 days'] as $when) {
            $this->play('Guest Track', $when);
        }
        $this->play('Second Song', '-3 days');
        $this->play('Second Song', '-2 days');
        $this->play('Opening', '-1 day');

        self::assertSame(['Guest Track', 'Second Song', 'Opening'], $this->titles($this->list('/api/me/top-tracks')));
        self::assertSame(['Second Song', 'Opening'], $this->titles($this->list('/api/me/top-tracks?period=month')), 'The plays of 40 days ago don\'t count.');
        self::assertSame([2, 1], array_column($this->list('/api/me/top-tracks?period=week'), 'playCount'));

        $this->client->request('GET', '/api/me/top-tracks?period=decade', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testTheSamePlaySentAgainWithinThirtySecondsCountsOnce(): void
    {
        $now = new \DateTimeImmutable('-1 hour');
        $this->play('Opening', $now->format(\DATE_ATOM));
        $this->play('Opening', $now->modify('+20 seconds')->format(\DATE_ATOM));
        // The same instant written with another offset is the same play too.
        $this->play('Opening', $now->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format(\DATE_ATOM));
        self::assertSame(1, $this->list('/api/me/recent-tracks')[0]['playCount']);

        $this->play('Opening', $now->modify('+5 minutes')->format(\DATE_ATOM));
        self::assertSame(2, $this->list('/api/me/recent-tracks')[0]['playCount'], 'Played again, later.');
    }

    public function testAPlayWithoutABodyIsNowAndOneInTheFutureOrTooOldIsRefused(): void
    {
        $this->client->request('POST', '/api/tracks/'.$this->trackId('Opening').'/plays', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(204);
        self::assertCount(1, $this->list('/api/me/recent-tracks'));

        foreach (['+1 hour', '-2 years', 'not a date'] as $when) {
            $this->post('Second Song', ['playedAt' => 'not a date' === $when ? $when : (new \DateTimeImmutable($when))->format(\DATE_ATOM)]);
            self::assertResponseStatusCodeSame(422, $when);
        }
        $this->client->request('POST', '/api/tracks/01a1c051-0000-7000-8000-000000000000/plays', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->list('/api/me/recent-tracks'));
    }

    public function testEachUserHasTheirOwnHistoryAndCanForgetIt(): void
    {
        $this->play('Opening');
        $mine = $this->token;
        $this->token = self::tokenFor(self::createUser('someone-else'));
        $this->play('Second Song');

        self::assertSame(['Second Song'], $this->titles($this->list('/api/me/recent-tracks')));
        $this->client->request('DELETE', '/api/me/history', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(204);
        self::assertSame([], $this->list('/api/me/recent-tracks'));

        $this->token = $mine;
        self::assertSame(['Opening'], $this->titles($this->list('/api/me/recent-tracks')), 'Only their own history was forgotten.');
    }

    public function testEverythingNeedsAJwt(): void
    {
        $this->client->request('POST', '/api/tracks/'.$this->trackId('Opening').'/plays');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('GET', '/api/me/recent-tracks');
        self::assertResponseStatusCodeSame(401);
        $this->client->request('DELETE', '/api/me/history');
        self::assertResponseStatusCodeSame(401);
    }

    public function testPlaysGoWithTheirTrackAndTheirUser(): void
    {
        $library = sys_get_temp_dir().'/echora-library-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(__DIR__.'/../Fixtures/music', $library);
        try {
            $scanner = self::getContainer()->get(LibraryScanner::class);
            $scanner->scan($library, download: false);
            $this->play('Opening');
            $this->play('Second Song');

            unlink($library.'/Test Artist/2020 - First Album/02 - Second Song.flac');
            $scanner->scan($library, download: false);

            self::assertSame(['Opening'], $this->titles($this->list('/api/me/recent-tracks')));
        } finally {
            (new Filesystem())->remove($library);
        }

        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');
        $user = $entityManager->find(User::class, $this->user->getId());
        self::assertInstanceOf(User::class, $user);
        $entityManager->remove($user);
        $entityManager->flush();
        self::assertSame(0, SqlValue::int(self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT COUNT(*) FROM play')));
    }

    public function testAPageCostsTheSameNumberOfQueriesWhateverItHolds(): void
    {
        $this->play('Opening');
        $few = $this->countQueries('/api/me/top-tracks');

        foreach (['Second Song', 'Guest Track', 'MP3 Song', 'untagged'] as $title) {
            $this->play($title);
        }
        $many = $this->countQueries('/api/me/top-tracks');

        self::assertGreaterThan(0, $few);
        self::assertSame($few, $many);
    }

    private function play(string $title, ?string $when = null): void
    {
        if (null === $when) {
            $this->client->request('POST', '/api/tracks/'.$this->trackId($title).'/plays', ['auth_bearer' => $this->token]);
        } else {
            $this->post($title, ['playedAt' => str_starts_with($when, '-') || str_starts_with($when, '+') ? (new \DateTimeImmutable($when))->format(\DATE_ATOM) : $when]);
        }
        self::assertSame(204, $this->client->getResponse()?->getStatusCode(), $title);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function post(string $title, array $json): ResponseInterface
    {
        return $this->client->request('POST', '/api/tracks/'.$this->trackId($title).'/plays', ['auth_bearer' => $this->token, 'json' => $json]);
    }

    /**
     * @return list<array<mixed>>
     */
    private function list(string $url): array
    {
        $list = $this->client->request('GET', $url, ['auth_bearer' => $this->token, 'headers' => ['Accept' => 'application/json']])->toArray();
        self::assertResponseIsSuccessful();

        return array_values(array_filter($list, \is_array(...)));
    }

    /**
     * @param list<array<mixed>> $list
     *
     * @return list<mixed>
     */
    private function titles(array $list): array
    {
        return array_map(static fn (array $item): mixed => \is_array($item['track']) ? $item['track']['title'] : null, $list);
    }

    private function trackId(string $title): string
    {
        $track = self::getContainer()->get(TrackRepository::class)->findOneBy(['title' => $title]);
        self::assertInstanceOf(Track::class, $track);

        return $track->getId()->toRfc4122();
    }

    private function countQueries(string $url): int
    {
        $this->client->request('GET', $url, ['auth_bearer' => $this->token]);

        return \count(self::getContainer()->get('doctrine.debug_data_holder')->getData()['default'] ?? []);
    }
}
