<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Track;
use App\Library\LibraryScanner;
use App\Library\OpusTranscoder;

/**
 * FLAC tracks are also served converted to Opus, through the opusStreamUrl of the track.
 */
final class OpusStreamTest extends ApiTestCase
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

    public function testFlacTracksComeWithASignedOpusStreamUrl(): void
    {
        $track = $this->fetchTrack('Opening');

        self::assertIsString($track['id']);
        self::assertIsString($track['opusStreamUrl']);
        self::assertStringContainsString('/api/tracks/'.$track['id'].'/stream/opus?', $track['opusStreamUrl']);
        self::assertStringContainsString('_hash=', $track['opusStreamUrl']);
    }

    public function testMp3TracksAreNotConverted(): void
    {
        $track = $this->fetchTrack('MP3 Song');

        self::assertArrayNotHasKey('opusStreamUrl', $track, 'Left out, like other null fields.');

        $this->client->request('GET', $this->iri('MP3 Song').'/stream/opus', ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheOpusVersionIsServedWithAJwt(): void
    {
        $this->client->request('GET', $this->iri('Opening').'/stream/opus', ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', OpusTranscoder::MIME_TYPE);
        self::assertResponseHeaderSame('accept-ranges', 'bytes');
        self::assertStringContainsString('private', (string) $this->client->getResponse()?->getHeaders()['cache-control'][0]);
        $body = $this->body();
        self::assertStringStartsWith('OggS', $body);
        self::assertStringContainsString('OpusHead', $body);
    }

    public function testTheSignedUrlWorksWithoutAuthorizationHeaderAndAnswersRangeRequests(): void
    {
        $url = $this->fetchTrack('Opening')['opusStreamUrl'];
        self::assertIsString($url);

        $this->client->request('GET', $url, ['headers' => ['Range' => 'bytes=0-99']]);

        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('content-length', '100');
        self::assertStringStartsWith('OggS', $this->body());
    }

    public function testTheSignatureOfTheOriginalStreamDoesNotOpenTheOpusVersion(): void
    {
        $url = $this->fetchTrack('Opening')['streamUrl'];
        self::assertIsString($url);

        $this->client->request('GET', str_replace('/stream?', '/stream/opus?', $url));

        self::assertResponseStatusCodeSame(401);
    }

    public function testBehindCaddyTheOpusFileIsHandedOverWithXAccelRedirect(): void
    {
        $mediaDir = self::getContainer()->getParameter('app.media_dir');
        $folder = \dirname($mediaDir).'/';

        $this->client->request('GET', $this->iri('Opening').'/stream/opus', ['auth_bearer' => $this->token, 'headers' => [
            'X-Sendfile-Type' => 'x-accel-redirect',
            'X-Accel-Mapping' => $folder.'='.$folder,
        ]]);

        self::assertResponseStatusCodeSame(200);
        $file = rawurldecode((string) $this->client->getResponse()?->getHeaders()['x-accel-redirect'][0]);
        self::assertStringStartsWith($mediaDir.'/transcodes/track-', $file);
        self::assertFileExists($file);
        self::assertSame('', $this->body(), 'Caddy sends the file, PHP sends nothing.');
    }

    public function testWithoutTokenOrSignatureNothingIsServed(): void
    {
        $this->client->request('GET', $this->iri('Opening').'/stream/opus');

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnUnknownTrackIsNotFound(): void
    {
        $this->client->request('GET', '/api/tracks/01a1c051-0000-7000-8000-000000000000/stream/opus', ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(404);
    }

    private function iri(string $title): string
    {
        $iri = $this->findIriBy(Track::class, ['title' => $title]);
        self::assertNotNull($iri);

        return $iri;
    }

    /**
     * @return array<mixed>
     */
    private function fetchTrack(string $title): array
    {
        return $this->client->request('GET', $this->iri($title), ['auth_bearer' => $this->token])->toArray();
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
