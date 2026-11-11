<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Everyone manages their own account through /api/me: name, username, email, password and picture.
 */
final class AccountTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private User $user;
    private string $token;

    /** @var list<string> */
    private array $files = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->user = self::createUser('listener', 'listener-pass', email: 'listener@example.com');
        $this->token = self::tokenFor($this->user);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), $this->files);
        parent::tearDown();
    }

    public function testTheAccountShowsItsNameEmailRoleAndPicture(): void
    {
        $me = $this->me();

        self::assertSame($this->user->getId()->toRfc4122(), $me['id']);
        self::assertSame(['listener', 'listener@example.com', 'listener', false], [$me['username'], $me['email'], $me['name'], $me['admin']]);
        self::assertArrayNotHasKey('avatarUrl', $me, 'Null fields are left out.');
        self::assertArrayNotHasKey('password', $me);
        self::assertArrayNotHasKey('avatar', $me);
    }

    public function testAccountsSignInWithTheirEmailOrTheirUsername(): void
    {
        foreach (['listener', 'listener@example.com', 'LISTENER@Example.com'] as $identifier) {
            $this->client->request('POST', '/api/auth', ['json' => ['username' => $identifier, 'password' => 'listener-pass']]);
            self::assertResponseIsSuccessful($identifier);
        }
    }

    public function testTheNameUsernameAndEmailCanBeChangedWithoutSigningOut(): void
    {
        $refreshToken = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'listener-pass']])->toArray()['refresh_token'];

        $changed = $this->patch('/api/me', ['name' => 'Lisa Listener', 'username' => 'lisa', 'email' => 'Lisa@Example.com'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertSame(['lisa', 'lisa@example.com', 'Lisa Listener'], [$changed['username'], $changed['email'], $changed['name']]);
        self::assertSame('lisa', $this->me()['username'], 'The JWT issued before still works: it names the account by id.');
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $refreshToken]]);
        self::assertResponseIsSuccessful('The refresh token issued before still works.');
        $this->client->request('POST', '/api/auth', ['json' => ['username' => 'lisa', 'password' => 'listener-pass']]);
        self::assertResponseIsSuccessful();
    }

    public function testAnAccountCannotMakeItselfAnAdministratorNorSetItsPasswordThatWay(): void
    {
        $this->patch('/api/me', ['admin' => true, 'password' => 'sneaky-new-pass', 'name' => 'Still me']);

        self::assertResponseIsSuccessful();
        $me = $this->me();
        self::assertFalse($me['admin']);
        self::assertSame('Still me', $me['name']);
        $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'listener-pass']]);
        self::assertResponseIsSuccessful('The password did not change.');
    }

    public function testUsernamesAndEmailsAreUniqueAndValid(): void
    {
        self::createUser('taken', email: 'taken@example.com');

        foreach ([
            ['username' => 'taken'],
            ['email' => 'TAKEN@example.com'],
            ['username' => 'no spaces'],
            ['username' => 'a@b'],
            ['email' => 'not an email'],
            ['name' => ''],
            ['name' => 'Trailing '],
        ] as $change) {
            $this->patch('/api/me', $change);
            self::assertResponseStatusCodeSame(422, (string) json_encode($change));
        }
        self::assertSame('listener', $this->me()['username']);
    }

    public function testChangingThePasswordSignsOutEveryDeviceAndGivesANewSession(): void
    {
        $otherDevice = $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'listener-pass']])->toArray()['refresh_token'];

        $this->post('/api/me/password', ['currentPassword' => 'wrong-pass', 'newPassword' => 'brand-new-pass']);
        self::assertResponseStatusCodeSame(422);
        $this->post('/api/me/password', ['currentPassword' => 'listener-pass', 'newPassword' => 'short']);
        self::assertResponseStatusCodeSame(422);

        $tokens = $this->post('/api/me/password', ['currentPassword' => 'listener-pass', 'newPassword' => 'brand-new-pass'])->toArray();

        self::assertResponseIsSuccessful();
        self::assertIsString($tokens['token']);
        self::assertIsString($tokens['refresh_token']);
        $this->client->request('POST', '/api/token/refresh', ['json' => ['refresh_token' => $otherDevice]]);
        self::assertResponseStatusCodeSame(401, 'The other device was signed out.');
        $this->client->request('POST', '/api/auth', ['json' => ['username' => 'listener', 'password' => 'brand-new-pass']]);
        self::assertResponseIsSuccessful();
    }

    public function testAPictureIsUploadedMadeSquareServedWithASignedUrlAndRemoved(): void
    {
        $uploaded = $this->upload('/api/me/avatar', $this->image(400, 200));

        self::assertResponseIsSuccessful();
        $url = $uploaded->toArray()['avatarUrl'];
        self::assertIsString($url);
        self::assertStringContainsString('/api/users/'.$this->user->getId()->toRfc4122().'/avatar?', $url);
        self::assertSame($url, $this->me()['avatarUrl']);

        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        $size = getimagesizefromstring($this->body());
        self::assertIsArray($size);
        self::assertSame([200, 200, \IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]], 'A centred square, encoded again as JPEG.');
        $this->client->request('GET', strtok($url, '?') ?: '');
        self::assertResponseStatusCodeSame(401, 'Without its signature (or a JWT), nothing is served.');

        $newUrl = $this->upload('/api/me/avatar', $this->image(50, 50))->toArray()['avatarUrl'];
        self::assertIsString($newUrl);
        self::assertNotSame($url, $newUrl, 'A new picture gets a new URL, so browsers don\'t show the old one.');

        $this->client->request('DELETE', '/api/me/avatar', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(204);
        self::assertArrayNotHasKey('avatarUrl', $this->me());
        $this->client->request('GET', $newUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyPicturesAreAccepted(): void
    {
        $text = $this->temporaryFile('not a picture');
        $this->upload('/api/me/avatar', $text);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/api/me/avatar', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(422, 'No file.');

        $large = $this->temporaryFile(random_bytes(6 * 1024 * 1024));
        $this->upload('/api/me/avatar', $large);
        self::assertResponseStatusCodeSame(422);
        self::assertArrayNotHasKey('avatarUrl', $this->me());
    }

    public function testOnlyAdministratorsChangeTheAccountsOfOthers(): void
    {
        $other = self::createUser('someone-else');

        $this->upload('/api/users/'.$other->getId()->toRfc4122().'/avatar', $this->image(10, 10));
        self::assertResponseStatusCodeSame(403);
        $this->patch('/api/users/'.$other->getId()->toRfc4122(), ['name' => 'Hacked']);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<mixed>
     */
    private function me(): array
    {
        return $this->client->request('GET', '/api/me', ['auth_bearer' => $this->token, 'headers' => ['Accept' => 'application/json']])->toArray();
    }

    /**
     * @param array<string, mixed> $json
     */
    private function patch(string $url, array $json): ResponseInterface
    {
        return $this->client->request('PATCH', $url, ['auth_bearer' => $this->token, 'json' => $json, 'headers' => ['Content-Type' => 'application/merge-patch+json', 'Accept' => 'application/json']]);
    }

    /**
     * @param array<string, mixed> $json
     */
    private function post(string $url, array $json): ResponseInterface
    {
        return $this->client->request('POST', $url, ['auth_bearer' => $this->token, 'json' => $json]);
    }

    private function upload(string $url, string $file): ResponseInterface
    {
        return $this->client->request('POST', $url, [
            'auth_bearer' => $this->token,
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['files' => ['file' => new UploadedFile($file, 'picture', test: true)]],
        ]);
    }

    /**
     * @param int<1, max> $width
     * @param int<1, max> $height
     */
    private function image(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        \assert(false !== $image);
        ob_start();
        imagepng($image);

        return $this->temporaryFile((string) ob_get_clean());
    }

    private function temporaryFile(string $content): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'echora-avatar-');
        file_put_contents($file, $content);
        $this->files[] = $file;

        return $file;
    }

    /**
     * The test client doesn't capture the body of a BinaryFileResponse (see TrackStreamTest::body()).
     */
    private function body(): string
    {
        ob_start();
        $this->client->getKernelBrowser()->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
