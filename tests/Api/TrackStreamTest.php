<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Library\AudioMetadata;
use App\Library\LibraryScanner;
use Doctrine\ORM\EntityManagerInterface;

final class TrackStreamTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    private const string FILE = __DIR__.'/../Fixtures/music/Test Artist/2020 - First Album/01 - Opening.flac';

    private Client $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::getContainer()->get(LibraryScanner::class)->scan();
    }

    public function testTheWholeFileIsServed(): void
    {
        $this->client->request('GET', $this->streamUrl('Opening'));

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'audio/flac');
        self::assertResponseHeaderSame('accept-ranges', 'bytes');
        self::assertResponseHeaderSame('content-length', (string) filesize(self::FILE));
        self::assertSame(file_get_contents(self::FILE), $this->body());
    }

    public function testARangeRequestReturnsOnlyThoseBytes(): void
    {
        $this->client->request('GET', $this->streamUrl('Opening'), ['headers' => ['Range' => 'bytes=100-199']]);

        self::assertResponseStatusCodeSame(206);
        self::assertResponseHeaderSame('content-range', \sprintf('bytes 100-199/%d', filesize(self::FILE)));
        self::assertResponseHeaderSame('content-length', '100');
        self::assertSame(substr((string) file_get_contents(self::FILE), 100, 100), $this->body());
    }

    public function testARangeOutsideTheFileIsRejected(): void
    {
        $size = (int) filesize(self::FILE);

        $this->client->request('GET', $this->streamUrl('Opening'), ['headers' => ['Range' => \sprintf('bytes=%d-%d', $size + 10, $size + 20)]]);

        self::assertResponseStatusCodeSame(416);
        self::assertResponseHeaderSame('content-range', \sprintf('bytes */%d', $size));
    }

    public function testAnOpenEndedRangeBeyondTheFileIsIgnored(): void
    {
        // RFC 9110 lets a server ignore Range; Symfony then sends the whole file.
        $this->client->request('GET', $this->streamUrl('Opening'), ['headers' => ['Range' => \sprintf('bytes=%d-', (int) filesize(self::FILE) + 10)]]);

        self::assertResponseStatusCodeSame(200);
    }

    public function testSharedCachesMustNotStoreTheAudio(): void
    {
        $this->client->request('GET', $this->streamUrl('Opening'));

        self::assertStringContainsString('private', (string) $this->client->getResponse()?->getHeaders()['cache-control'][0]);
    }

    public function testMp3FilesAreServedAsAudioMpeg(): void
    {
        $this->client->request('GET', $this->streamUrl('MP3 Song'));

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'audio/mpeg');
    }

    public function testAnUnknownTrackIsNotFound(): void
    {
        $this->client->request('GET', '/api/tracks/01a1c051-0000-7000-8000-000000000000/stream');

        self::assertResponseStatusCodeSame(404);
    }

    public function testATrackWhoseFileDisappearedIsNotFound(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $artist = new Artist('Gone Artist');
        $album = new Album($artist, 'Gone Album');
        $track = new Track('Gone Artist/Gone Album/01 - Gone.flac', $album, $artist, new AudioMetadata(
            title: 'Gone', artist: 'Gone Artist', albumArtist: 'Gone Artist', album: 'Gone Album', year: null,
            trackNumber: 1, discNumber: null, genre: null, durationMs: 1000,
            format: 'flac', mimeType: 'audio/flac', fileSize: 1, fileModifiedAt: 0,
        ));
        array_map($entityManager->persist(...), [$artist, $album, $track]);
        $entityManager->flush();

        $this->client->request('GET', '/api/tracks/'.$track->getId()->toRfc4122().'/stream');

        self::assertResponseStatusCodeSame(404);
    }

    private function streamUrl(string $title): string
    {
        $iri = $this->findIriBy(Track::class, ['title' => $title]);
        self::assertNotNull($iri);

        return $iri.'/stream';
    }

    /**
     * The test client doesn't capture the body of a BinaryFileResponse:
     * send it to a buffer, as the web server would send it to the network.
     */
    private function body(): string
    {
        ob_start();
        $this->client->getKernelBrowser()->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
