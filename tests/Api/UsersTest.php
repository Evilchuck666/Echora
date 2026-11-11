<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Doctrine\SqlValue;
use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Administrators manage every account through /api/users; nobody else can.
 */
final class UsersTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->admin = self::createUser('admin', 'admin-pass', admin: true, email: 'admin@example.com');
        $this->token = self::tokenFor($this->admin);
    }

    public function testAnAdministratorCreatesAnAccountWhoseUsernameComesFromItsEmail(): void
    {
        $created = $this->request('POST', '/api/users', ['email' => 'Bob.Smith@Example.com', 'name' => 'Bob Smith', 'password' => 'bob-password']);

        self::assertResponseStatusCodeSame(201);
        $bob = $created->toArray();
        self::assertSame(['bob.smith', 'bob.smith@example.com', 'Bob Smith', false], [$bob['username'], $bob['email'], $bob['name'], $bob['admin']]);
        $this->client->request('POST', '/api/auth', ['json' => ['username' => 'bob.smith@example.com', 'password' => 'bob-password']]);
        self::assertResponseIsSuccessful();

        $second = $this->request('POST', '/api/users', ['email' => 'bob.smith@elsewhere.example', 'name' => 'Another Bob', 'password' => 'bob-password', 'admin' => true])->toArray();
        self::assertSame(['bob.smith-2', true], [$second['username'], $second['admin']], 'A taken username gets a number.');
        self::assertSame('al-1', $this->request('POST', '/api/users', ['email' => 'al@example.com', 'name' => 'Al', 'password' => 'al-password'])->toArray()['username'], 'Too short: a number too.');
    }

    public function testAnAccountNeedsAFreeValidEmailANameAndAPassword(): void
    {
        foreach ([
            ['email' => 'ADMIN@example.com', 'name' => 'Copy', 'password' => 'copy-password'],
            ['email' => 'not an email', 'name' => 'Bob', 'password' => 'bob-password'],
            ['email' => 'bob@example.com', 'name' => '', 'password' => 'bob-password'],
            ['email' => 'bob@example.com', 'name' => 'Bob', 'password' => 'short'],
        ] as $body) {
            $this->request('POST', '/api/users', $body);
            self::assertResponseStatusCodeSame(422, (string) json_encode($body));
        }
        self::assertSame(1, self::getContainer()->get(UserRepository::class)->count());
    }

    public function testAccountsAreListedByUsernameAndCanBeRead(): void
    {
        $zoe = self::createUser('zoe');
        self::createUser('bob');

        $list = $this->request('GET', '/api/users')->toArray();

        self::assertSame(['admin', 'bob', 'zoe'], array_column($list, 'username'));
        self::assertSame('zoe', $this->request('GET', '/api/users/'.$zoe->getId()->toRfc4122())->toArray()['username']);
        $this->request('GET', '/api/users/01a1c051-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnAdministratorChangesAnyAccount(): void
    {
        $bob = self::createUser('bob', 'bob-password');
        $bobsSession = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'bob', 'password' => 'bob-password']])->toArray()['refresh_token'];

        $changed = $this->patch('/api/users/'.$bob->getId()->toRfc4122(), ['name' => 'Robert', 'username' => 'robert', 'email' => 'robert@example.com', 'admin' => true])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['robert', 'robert@example.com', 'Robert', true], [$changed['username'], $changed['email'], $changed['name'], $changed['admin']]);
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $bobsSession]]);
        self::assertResponseIsSuccessful('Renaming keeps the sessions.');

        $newSession = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'robert', 'password' => 'bob-password']])->toArray()['refresh_token'];
        $this->patch('/api/users/'.$bob->getId()->toRfc4122(), ['password' => 'reset-by-admin']);
        self::assertResponseIsSuccessful();
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $newSession]]);
        self::assertResponseStatusCodeSame(401, 'A new password signs the account out.');
        $this->client->request('POST', '/api/auth', ['json' => ['username' => 'robert', 'password' => 'reset-by-admin']]);
        self::assertResponseIsSuccessful();
    }

    public function testTheServerNeverRunsOutOfAdministrators(): void
    {
        $me = '/api/users/'.$this->admin->getId()->toRfc4122();

        $this->patch($me, ['admin' => false]);
        self::assertResponseStatusCodeSame(409);
        self::assertTrue($this->request('GET', $me)->toArray()['admin']);

        $other = self::createUser('second-admin', admin: true);
        $this->patch('/api/users/'.$other->getId()->toRfc4122(), ['admin' => false]);
        self::assertResponseIsSuccessful('Another administrator is left.');
        $this->patch('/api/users/'.$other->getId()->toRfc4122(), ['admin' => true]);
        $this->patch($me, ['admin' => false]);
        self::assertResponseIsSuccessful('Now the other one is.');
    }

    public function testAnAdministratorDeletesOtherAccountsButNotTheirOwn(): void
    {
        $bob = self::createUser('bob', 'bob-password');
        $bobsToken = self::tokenFor($bob);
        $playlist = $this->client->request('POST', '/api/playlists', ['auth_bearer' => $bobsToken, 'json' => ['name' => 'Bob\'s']]);
        self::assertSame(201, $playlist->getStatusCode());

        $this->request('DELETE', '/api/users/'.$this->admin->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(409);

        $this->request('DELETE', '/api/users/'.$bob->getId()->toRfc4122());
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/me', ['auth_bearer' => $bobsToken]);
        self::assertResponseStatusCodeSame(401, 'The JWT stops working with the account.');
        self::assertSame(0, SqlValue::int(self::getContainer()->get('doctrine.dbal.default_connection')->fetchOne('SELECT COUNT(*) FROM playlist')));
    }

    public function testOnlyAdministratorsManageAccounts(): void
    {
        $bob = self::createUser('bob');
        $this->token = self::tokenFor($bob);
        $admin = '/api/users/'.$this->admin->getId()->toRfc4122();

        $this->request('GET', '/api/users');
        self::assertResponseStatusCodeSame(403);
        $this->request('POST', '/api/users', ['email' => 'eve@example.com', 'name' => 'Eve', 'password' => 'eve-password']);
        self::assertResponseStatusCodeSame(403);
        $this->request('GET', $admin);
        self::assertResponseStatusCodeSame(403);
        $this->patch($admin, ['name' => 'Hacked']);
        self::assertResponseStatusCodeSame(403);
        $this->request('DELETE', $admin);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/users');
        self::assertResponseStatusCodeSame(401, 'And nothing without a JWT.');
    }

    /**
     * @param array<string, mixed>|null $json
     */
    private function request(string $method, string $url, ?array $json = null): ResponseInterface
    {
        $options = ['auth_bearer' => $this->token, 'headers' => ['Accept' => 'application/json']];
        if (null !== $json) {
            $options['json'] = $json;
        }

        return $this->client->request($method, $url, $options);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function patch(string $url, array $json): ResponseInterface
    {
        return $this->client->request('PATCH', $url, ['auth_bearer' => $this->token, 'json' => $json, 'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/json']]);
    }
}
