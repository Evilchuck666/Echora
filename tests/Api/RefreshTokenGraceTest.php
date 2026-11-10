<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Security\RefreshTokenSuccessors;
use Doctrine\DBAL\Connection;
use Psr\Cache\CacheItemPoolInterface;

/**
 * The grace period for refresh tokens whose new pair never reached the client (App\Security\RefreshTokenGraceListener).
 */
final class RefreshTokenGraceTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::createUser('listener', 'listener-pass');
    }

    public function testASpentTokenWhoseNewPairWasLostStillWorks(): void
    {
        $first = $this->signIn();
        $this->refresh($first); // The response is lost: the client still holds $first.

        $response = $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first]]);

        self::assertResponseIsSuccessful();
        $third = $response->toArray()['refresh_token'];
        self::assertIsString($third);
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $third]]);
        self::assertResponseIsSuccessful('The session goes on.');
    }

    public function testSeveralLostResponsesInARowAreFollowed(): void
    {
        $first = $this->signIn();
        $this->refresh($first);
        $this->refresh($first);

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first]]);

        self::assertResponseIsSuccessful();
    }

    public function testTheSuccessorTakenInsteadIsSpent(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first);
        $this->refresh($first);

        // $second was spent by the retry above: presenting it is reuse.
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $second]]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testASpentTokenWhoseSuccessorWasUsedStillRevokesTheSession(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first);
        $third = $this->refresh($second); // The client did get $second, and used it.

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first]]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $third]]);
        self::assertResponseStatusCodeSame(401, 'Reuse detection revoked the whole chain.');
    }

    public function testAfterTheGracePeriodASpentTokenRevokesTheSession(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first);
        // The grace period is over: what the cache knew about the successor has expired.
        $this->pool()->deleteItem(RefreshTokenSuccessors::cacheKey($first));

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first]]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $second]]);
        self::assertResponseStatusCodeSame(401, 'Reuse detection revoked the whole chain.');
    }

    public function testATheftWithinTheGracePeriodIsDetectedWhenTheOwnerComesBack(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first); // The owner holds $second.

        // Someone else replays the spent $first and gets a session...
        $stolen = $this->refresh($first);

        // ...until the owner uses $second, which that replay spent: the whole chain is revoked.
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $second]]);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $stolen]]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testASignedOutSessionHasNoGracePeriod(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first);
        $this->client->request('POST', '/api/logout', ['json' => ['refresh_token' => $second]]);

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first]]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheSuccessorIsNotStoredInTheClear(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first);

        $rows = self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT item_data FROM cache_items');
        $stored = implode('', array_map(static fn (mixed $data): string => match (true) {
            \is_resource($data) => (string) stream_get_contents($data),
            \is_string($data) => $data,
            default => '',
        }, $rows));

        self::assertNotSame('', $stored, 'The bookkeeping is in the database.');
        self::assertStringNotContainsString($second, $stored);
        self::assertStringNotContainsString($first, $stored);
    }

    public function testReuseIsStillRecognisedWhenTheApplicationCacheIsCleared(): void
    {
        $first = $this->signIn();
        $second = $this->refresh($first);
        $this->refresh($second);
        // As after a deploy: var/cache starts empty, the database doesn't.
        self::getContainer()->get('cache.app')->clear();

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first]]);
        self::assertResponseStatusCodeSame(401);
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $second]]);
        self::assertResponseStatusCodeSame(401, 'The replay revoked the chain: the spent token was remembered.');
    }

    private function signIn(): string
    {
        $token = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'listener-pass']])->toArray()['refresh_token'];
        self::assertIsString($token);

        return $token;
    }

    private function refresh(string $refreshToken): string
    {
        $response = $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $refreshToken]]);
        self::assertResponseIsSuccessful();
        $token = $response->toArray()['refresh_token'];
        self::assertIsString($token);

        return $token;
    }

    private function pool(): CacheItemPoolInterface
    {
        return self::getContainer()->get('cache.refresh_tokens');
    }
}
