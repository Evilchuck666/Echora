<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use App\Repository\UserRepository;

/**
 * Setting up a new server (like Immich): until it has an account, whoever comes first creates the administrator.
 */
final class SetupTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    public function testTheFirstAccountBecomesTheAdministratorAndIsSignedIn(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/setup', ['headers' => ['Accept' => 'application/json']]);
        self::assertResponseIsSuccessful();
        self::assertJsonContains(['required' => true]);

        $response = $client->request('POST', '/api/setup', ['json' => ['email' => 'Ana@Example.com', 'name' => 'Ana', 'password' => 'first-admin-pass']]);

        self::assertResponseStatusCodeSame(201);
        $tokens = $response->toArray();
        self::assertIsString($tokens['token']);
        self::assertIsString($tokens['refresh_token']);
        $me = $client->request('GET', '/api/me', ['auth_bearer' => $tokens['token']])->toArray();
        self::assertSame(['ana', 'ana@example.com', 'Ana', true], [$me['username'], $me['email'], $me['name'], $me['admin']]);

        $client->request('POST', '/api/auth', ['json' => ['username' => 'ana@example.com', 'password' => 'first-admin-pass']]);
        self::assertResponseIsSuccessful();
    }

    public function testOnceTheServerHasAnAccountSetupIsClosed(): void
    {
        $client = self::createClient();
        self::createUser();

        $client->request('GET', '/api/setup', ['headers' => ['Accept' => 'application/json']]);
        self::assertJsonContains(['required' => false]);

        $client->request('POST', '/api/setup', ['json' => ['email' => 'intruder@example.com', 'name' => 'Intruder', 'password' => 'intruder-pass']]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame(1, self::getContainer()->get(UserRepository::class)->count());
    }

    public function testTheFirstAccountNeedsAValidEmailNameAndPassword(): void
    {
        $client = self::createClient();

        foreach ([
            ['email' => 'not an email', 'name' => 'Ana', 'password' => 'first-admin-pass'],
            ['email' => 'ana@example.com', 'name' => ' Ana', 'password' => 'first-admin-pass'],
            ['email' => 'ana@example.com', 'name' => 'Ana', 'password' => 'short'],
        ] as $body) {
            $client->request('POST', '/api/setup', ['json' => $body]);
            self::assertResponseStatusCodeSame(422);
        }
        self::assertSame(0, self::getContainer()->get(UserRepository::class)->count());
    }
}
