<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use PHPUnit\Framework\Attributes\DataProvider;

final class AuthenticationTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::createUser('listener', 'listener-pass');
    }

    public function testSigningInReturnsAJwtAndARefreshToken(): void
    {
        $response = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'listener-pass']]);

        self::assertResponseIsSuccessful();
        $body = $response->toArray();
        self::assertIsString($body['token']);
        self::assertIsString($body['refresh_token']);
        self::assertIsInt($body['refresh_token_expiration']);
    }

    public function testAWrongPasswordIsRejected(): void
    {
        $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'wrong-password']]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeDescribesTheAuthenticatedAccount(): void
    {
        $this->client->request('GET', '/api/me', ['auth_bearer' => $this->signIn()['token']]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['username' => 'listener', 'roles' => ['ROLE_USER']]);
        self::assertStringNotContainsString('password', (string) $this->client->getResponse()?->getContent());
    }

    #[DataProvider('protectedEndpoints')]
    public function testTheApiRequiresAToken(string $url): void
    {
        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function protectedEndpoints(): iterable
    {
        yield 'artists' => ['/api/artists'];
        yield 'albums' => ['/api/albums'];
        yield 'tracks' => ['/api/tracks'];
        yield 'me' => ['/api/me'];
        yield 'search' => ['/api/search?q=test'];
    }

    public function testAnInvalidTokenIsRejected(): void
    {
        $this->client->request('GET', '/api/artists', ['auth_bearer' => 'not-a-jwt']);

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheDocumentationIsPublic(): void
    {
        $this->client->request('GET', '/api/docs', ['headers' => ['Accept' => 'application/vnd.openapi+json']]);

        self::assertResponseIsSuccessful();
    }

    public function testARefreshTokenGivesANewJwtAndIsReplaced(): void
    {
        $first = $this->signIn();

        $response = $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first['refresh_token']]]);

        self::assertResponseIsSuccessful();
        $second = $response->toArray();
        self::assertIsString($second['token']);
        self::assertNotSame($first['refresh_token'], $second['refresh_token'], 'Refresh tokens are single use.');

        $this->client->request('GET', '/api/me', ['auth_bearer' => $second['token']]);
        self::assertResponseIsSuccessful();
    }

    public function testReusingASpentRefreshTokenRevokesTheWholeChain(): void
    {
        $first = $this->signIn();
        $second = $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first['refresh_token']]])->toArray();
        $third = $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $second['refresh_token']]])->toArray();

        // Someone replays a spent token (e.g. it was stolen); its successor was used, so no grace period applies
        // (see RefreshTokenGraceTest)...
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $first['refresh_token']]]);
        self::assertResponseStatusCodeSame(401);

        // ...so the latest token stops working too.
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $third['refresh_token']]]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSigningOutInvalidatesTheRefreshToken(): void
    {
        $session = $this->signIn();

        $this->client->request('POST', '/api/logout', ['json' => ['refresh_token' => $session['refresh_token']]]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['message' => 'The supplied refresh_token has been invalidated.']);

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $session['refresh_token']]]);
        self::assertResponseStatusCodeSame(401);
    }

    public function testSigningOutTwiceIsNotAnError(): void
    {
        $session = $this->signIn();
        $this->client->request('POST', '/api/logout', ['json' => ['refresh_token' => $session['refresh_token']]]);

        $this->client->request('POST', '/api/logout', ['json' => ['refresh_token' => $session['refresh_token']]]);

        self::assertResponseIsSuccessful();
        self::assertJsonContains(['message' => 'The supplied refresh_token is already invalid.']);
    }

    public function testSigningOutLeavesOtherSessionsAlone(): void
    {
        $phone = $this->signIn();
        $laptop = $this->signIn();

        $this->client->request('POST', '/api/logout', ['json' => ['refresh_token' => $phone['refresh_token']]]);

        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $laptop['refresh_token']]]);
        self::assertResponseIsSuccessful();
    }

    public function testSigningOutNeedsARefreshToken(): void
    {
        $this->client->request('POST', '/api/logout', ['json' => []]);

        self::assertResponseStatusCodeSame(400);
    }

    public function testTheDocumentationListsTheAuthenticationEndpointsByPath(): void
    {
        $paths = $this->client->request('GET', '/api/docs', ['headers' => ['Accept' => 'application/vnd.openapi+json']])->toArray()['paths'];

        self::assertIsArray($paths);
        self::assertArrayHasKey('/api/auth', $paths);
        self::assertArrayHasKey('/api/token/refresh', $paths);
        self::assertArrayHasKey('/api/logout', $paths);
        self::assertArrayNotHasKey('api_refresh_token', $paths, 'The refresh endpoint is documented by its path, not its route name.');
    }

    /**
     * @return array{token: string, refresh_token: string}
     */
    private function signIn(): array
    {
        $body = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'listener-pass']])->toArray();
        self::assertIsString($body['token']);
        self::assertIsString($body['refresh_token']);

        return ['token' => $body['token'], 'refresh_token' => $body['refresh_token']];
    }
}
