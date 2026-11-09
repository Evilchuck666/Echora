<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Library\LibraryScanner;
use App\Library\SignedUrlGenerator;

final class AlbumCoverTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private const string COVER = __DIR__.'/../Fixtures/music/Test Artist/2020 - First Album/folder.jpg';

    private Client $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->token = self::tokenFor(self::createUser());
        self::getContainer()->get(LibraryScanner::class)->scan();
    }

    public function testTheCoverIsServedWithAJwt(): void
    {
        $this->client->request('GET', $this->coverPath('First Album'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        self::assertResponseHeaderSame('content-length', (string) filesize(self::COVER));
    }

    public function testThePngCoverIsServedAsPng(): void
    {
        $this->client->request('GET', $this->coverPath('Second Album'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'image/png');
    }

    public function testTheSignedCoverUrlWorksWithoutAuthorizationHeaderAndCanBeCached(): void
    {
        $url = $this->coverUrl('First Album');

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(200);
        $cacheControl = (string) $this->client->getResponse()?->getHeaders()['cache-control'][0];
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
    }

    public function testTheSignedCoverUrlStaysTheSameDuringTheDay(): void
    {
        $url = $this->coverUrl('First Album');

        self::assertSame($url, $this->coverUrl('First Album'), 'A stable URL lets browsers cache the image.');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame((string) SignedUrlGenerator::coverExpiration()->getTimestamp(), $query['_expiration'] ?? null);
    }

    public function testATamperedSignatureIsRejected(): void
    {
        $this->client->request('GET', str_replace('_hash=', '_hash=x', $this->coverUrl('First Album')));

        self::assertResponseStatusCodeSame(401);
    }

    public function testWithoutTokenOrSignatureTheCoverIsNotServed(): void
    {
        $this->client->request('GET', $this->coverPath('First Album'));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnAlbumWithoutCoverHasNone(): void
    {
        $this->client->request('GET', $this->coverPath('Compilation'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(404);
    }

    private function coverPath(string $title): string
    {
        $iri = $this->findIriBy(Album::class, ['title' => $title]);
        self::assertNotNull($iri);

        return $iri.'/cover';
    }

    private function coverUrl(string $title): string
    {
        $iri = $this->findIriBy(Album::class, ['title' => $title]);
        self::assertNotNull($iri);
        $url = $this->client->request('GET', $iri, ['auth_bearer' => $this->token])->toArray()['coverUrl'] ?? null;
        self::assertIsString($url);

        return $url;
    }
}
