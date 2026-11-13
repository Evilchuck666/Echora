<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Guessing passwords is throttled (docs/decisions/0014): 5 failed sign-ins per account and address, 25 per address,
 * in 15 minutes; the same for wrong current passwords given to POST /api/me/password, per account.
 */
final class SignInThrottlingTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        // One container for the whole test: the attempts are counted in an in-memory cache in tests.
        $this->client->disableReboot();
        self::createUser('listener', 'listener-pass', email: 'listener@example.com');
    }

    public function testAfterFiveFailedSignInsTheAccountIsLockedForAWhileEvenWithTheRightPassword(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->signIn('listener', 'wrong-pass');
            self::assertResponseStatusCodeSame(401, "Attempt $attempt");
        }

        $response = $this->signIn('listener', 'listener-pass');

        self::assertResponseStatusCodeSame(429);
        // About the 15 minutes of the window, in whole minutes, as Symfony rounds them up.
        $retryAfter = (int) ($response->getHeaders(false)['retry-after'][0] ?? 0);
        self::assertGreaterThan(0, $retryAfter);
        self::assertLessThanOrEqual(30 * 60, $retryAfter);
        self::assertSame(0, $retryAfter % 60);
        $body = $response->toArray(false);
        self::assertSame(429, $body['code']);
        self::assertSame(\sprintf('Too many failed sign-in attempts: try again in %d minutes.', $retryAfter / 60), $body['message']);
    }

    public function testOtherAccountsCanStillSignInFromTheSameAddress(): void
    {
        self::createUser('someone-else', 'someone-pass');
        for ($attempt = 1; $attempt <= 6; ++$attempt) {
            $this->signIn('listener', 'wrong-pass');
        }

        $this->signIn('someone-else', 'someone-pass');

        self::assertResponseIsSuccessful();
    }

    public function testASuccessfulSignInClearsTheCountOfTheAccount(): void
    {
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->signIn('listener', 'wrong-pass');
        }
        $this->signIn('listener@example.com', 'listener-pass');
        self::assertResponseIsSuccessful();
        // The email and the username of an account are told apart, like any two identifiers.
        $this->signIn('listener', 'listener-pass');
        self::assertResponseIsSuccessful();

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->signIn('listener', 'wrong-pass');
            self::assertResponseStatusCodeSame(401, "Attempt $attempt after signing in");
        }
    }

    public function testExpiredJwtsAndSpentRefreshTokensAreNotFailedSignIns(): void
    {
        // More than the 25 failures an address may have: a client waking up with an expired JWT fires many requests.
        for ($request = 1; $request <= 30; ++$request) {
            $this->client->request('GET', '/api/me', ['auth_bearer' => 'expired.or.forged']);
            self::assertResponseStatusCodeSame(401);
            $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => 'spent-refresh-token']]);
            self::assertResponseStatusCodeSame(401);
        }

        $this->signIn('listener', 'listener-pass');

        self::assertResponseIsSuccessful();
    }

    public function testOneAddressTryingManyAccountsIsLockedOut(): void
    {
        for ($attempt = 1; $attempt <= 25; ++$attempt) {
            $this->signIn("nobody-$attempt", 'wrong-pass');
            self::assertResponseStatusCodeSame(401);
        }

        $this->signIn('listener', 'listener-pass');

        self::assertResponseStatusCodeSame(429, 'Every account is locked for that address now.');
    }

    public function testWrongCurrentPasswordsAreThrottledToo(): void
    {
        $token = $this->signIn('listener', 'listener-pass')->toArray()['token'];
        self::assertIsString($token);
        $change = fn (string $current): ResponseInterface => $this->client->request('POST', '/api/me/password', [
            'auth_bearer' => $token,
            'json' => ['currentPassword' => $current, 'newPassword' => 'brand-new-pass'],
        ]);

        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $change('wrong-pass');
            self::assertResponseStatusCodeSame(422, "Attempt $attempt");
        }
        $change('wrong-pass');
        self::assertResponseStatusCodeSame(422);

        $change('listener-pass');
        self::assertResponseStatusCodeSame(429, 'Even the right password, once locked.');
        self::assertTrue($this->client->getResponse()?->getHeaders(false)['retry-after'][0] > 0);
        $this->signIn('listener', 'listener-pass');
        self::assertResponseIsSuccessful('The password did not change.');
    }

    private function signIn(string $username, string $password): ResponseInterface
    {
        return $this->client->request('POST', '/api/auth', ['json' => ['username' => $username, 'password' => $password]]);
    }
}
