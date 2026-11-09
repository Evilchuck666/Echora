<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Track;
use App\Library\LibraryScanner;
use App\Library\SignedUrlGenerator;
use Symfony\Component\HttpFoundation\UriSigner;

/**
 * Players can't send an Authorization header: the streamUrl of a track carries a signature instead.
 */
final class SignedStreamUrlTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $token;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->token = self::tokenFor(self::createUser());
        self::getContainer()->get(LibraryScanner::class)->scan();
    }

    public function testEveryTrackComesWithASignedStreamUrl(): void
    {
        $track = $this->fetchTrack('Opening');

        self::assertIsString($track['streamUrl']);
        self::assertIsString($track['id']);
        self::assertStringContainsString('/api/tracks/'.$track['id'].'/stream?', $track['streamUrl']);
        self::assertStringContainsString('_hash=', $track['streamUrl']);

        parse_str((string) parse_url($track['streamUrl'], \PHP_URL_QUERY), $query);
        self::assertIsNumeric($query['_expiration'] ?? null);
        self::assertEqualsWithDelta(time() + SignedUrlGenerator::STREAM_LIFETIME_SECONDS, (int) $query['_expiration'], 60, 'Valid for a few hours from now.');
    }

    public function testTheSignedUrlWorksWithoutAuthorizationHeader(): void
    {
        $url = $this->fetchTrack('Opening')['streamUrl'];
        self::assertIsString($url);

        $this->client->request('GET', $url, ['headers' => ['Range' => 'bytes=0-99']]);

        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('content-type', 'audio/flac');
    }

    public function testATamperedSignatureIsRejected(): void
    {
        $url = $this->fetchTrack('Opening')['streamUrl'];
        self::assertIsString($url);

        $this->client->request('GET', str_replace('_hash=', '_hash=x', $url));

        self::assertResponseStatusCodeSame(401);
    }

    public function testTheSignatureOfOneTrackDoesNotOpenAnother(): void
    {
        $opening = $this->fetchTrack('Opening');
        $other = $this->fetchTrack('MP3 Song');
        self::assertIsString($opening['streamUrl']);
        self::assertIsString($opening['id']);
        self::assertIsString($other['id']);

        $this->client->request('GET', str_replace($opening['id'], $other['id'], $opening['streamUrl']));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnExpiredSignatureIsRejected(): void
    {
        $iri = $this->findIriBy(Track::class, ['title' => 'Opening']);
        $expired = self::getContainer()->get(UriSigner::class)->sign('http://localhost'.$iri.'/stream', new \DateTimeImmutable('-1 minute'));

        $this->client->request('GET', $expired);

        self::assertResponseStatusCodeSame(401);
    }

    public function testWithoutTokenOrSignatureNothingIsServed(): void
    {
        $iri = $this->findIriBy(Track::class, ['title' => 'Opening']);

        $this->client->request('GET', $iri.'/stream');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @return array<mixed>
     */
    private function fetchTrack(string $title): array
    {
        $iri = $this->findIriBy(Track::class, ['title' => $title]);
        self::assertNotNull($iri);

        return $this->client->request('GET', $iri, ['auth_bearer' => $this->token])->toArray();
    }
}
