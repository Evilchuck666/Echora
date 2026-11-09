<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Artist;
use App\Library\LibraryScanner;
use App\Library\ScanReport;
use App\Library\TheAudioDb;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The fixtures (tests/Fixtures/music) give one artist of each kind: Test Artist has artist.jpg
 * and Folder.PNG in its folder, Various Artists has a folder without image, Unknown Artist's
 * album sits at the root of the library, and Guest Artist only appears on a track.
 */
final class ArtistCoverTest extends ApiTestCase
{
    use CreatesUsers;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $token;

    /** @var list<string> Artists searched on the fake TheAudioDB */
    private array $searches = [];

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->token = self::tokenFor(self::createUser());
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(self::getContainer()->getParameter('app.media_dir'));
        parent::tearDown();
    }

    public function testTheImageIsFoundInTheArtistFolder(): void
    {
        $this->scan();

        self::assertSame('Test Artist/artist.jpg', $this->artist('Test Artist')->getImagePath(), '"artist" comes before "folder".');
        self::assertNull($this->artist('Various Artists')->getImagePath(), 'Its folder has no image.');
        self::assertNull($this->artist('Unknown Artist')->getImagePath(), 'Its album is at the root of the library: no artist folder.');
        self::assertNull($this->artist('Guest Artist')->getImagePath(), 'It has no album: no artist folder.');
    }

    public function testOnlyArtistsWithAnImageHaveACoverUrl(): void
    {
        $this->scan();

        $artists = $this->client->request('GET', '/api/artists', ['auth_bearer' => $this->token])->toArray()['member'];
        self::assertIsArray($artists);
        $coverUrls = array_column($artists, 'coverUrl', 'name');

        self::assertSame(['Test Artist'], array_keys($coverUrls));
        self::assertIsString($coverUrls['Test Artist']);
        self::assertStringContainsString('/cover?', $coverUrls['Test Artist']);
    }

    public function testTheThumbnailIsServedWithAJwtAndCanBeCached(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('Test Artist'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        $cacheControl = (string) $this->client->getResponse()?->getHeaders()['cache-control'][0];
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
        // The 8×8 artist.jpg, as a JPEG no larger than the original.
        self::assertSame([8, 8, \IMAGETYPE_JPEG], \array_slice((array) getimagesizefromstring($this->body()), 0, 3));
    }

    public function testTheSignedCoverUrlWorksWithoutAuthorizationHeader(): void
    {
        $this->scan();
        $url = $this->client->request('GET', (string) $this->findIriBy(Artist::class, ['name' => 'Test Artist']), ['auth_bearer' => $this->token])->toArray()['coverUrl'] ?? null;
        self::assertIsString($url);

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(200);
    }

    public function testWithoutCredentialsTheImageIsRefused(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('Test Artist'));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnArtistWithoutImageHasNoCover(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('Various Artists'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testImagesMissingFromTheArtistFoldersAreDownloadedFromTheAudioDb(): void
    {
        $this->fakeTheAudioDb(['Guest Artist']);

        $report = $this->scan();

        sort($this->searches);
        self::assertSame(['Guest Artist', 'Unknown Artist', 'Various Artists'], $this->searches, 'Test Artist has an image of its own.');
        self::assertSame(1, $report->artistImagesDownloaded);
        self::assertNotNull($this->artist('Guest Artist')->getDownloadedImage());

        $this->client->request('GET', $this->coverPath('Guest Artist'), ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([16, 16], \array_slice((array) getimagesizefromstring($this->body()), 0, 2));
    }

    public function testArtistsAreNotLookedUpAgainBeforeAMonth(): void
    {
        $this->fakeTheAudioDb(['Guest Artist']);
        $this->scan();
        $this->searches = [];

        $this->scan();

        self::assertSame([], $this->searches);
    }

    public function testTheRateLimitStopsTheLookupsButNotTheScan(): void
    {
        $this->fakeTheAudioDb([], status: 429);

        $report = $this->scan();

        self::assertCount(1, $this->searches, 'No more requests after a 429.');
        self::assertStringContainsString('429', (string) $report->artistImageLookupFailure);
        self::assertSame('Test Artist/artist.jpg', $this->artist('Test Artist')->getImagePath(), 'Local images are still found.');
    }

    public function testOfflineScansDontContactTheAudioDb(): void
    {
        $this->fakeTheAudioDb(['Guest Artist']);

        self::getContainer()->get(LibraryScanner::class)->scan(download: false);

        self::assertSame([], $this->searches);
    }

    private function scan(): ScanReport
    {
        // Scans tests/Fixtures/music (MUSIC_LIBRARY_PATH in .env.test).
        return self::getContainer()->get(LibraryScanner::class)->scan();
    }

    /**
     * Replaces TheAudioDB with a fake that knows the given artists, with a 16×16 image each.
     *
     * @param list<string> $known
     */
    private function fakeTheAudioDb(array $known, int $status = 200): void
    {
        $image = imagecreatetruecolor(16, 16);
        \assert(false !== $image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string) ob_get_clean();

        $client = new MockHttpClient(function (string $method, string $url) use ($known, $status, $jpeg): MockResponse {
            if (str_contains($url, '/images/')) {
                return new MockResponse($jpeg, ['response_headers' => ['content-type' => 'image/jpeg']]);
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $name = $query['s'] ?? null;
            \assert(\is_string($name));
            $this->searches[] = $name;
            $artists = \in_array($name, $known, true)
                ? [['strArtist' => $name, 'strArtistThumb' => 'https://r2.theaudiodb.com/images/media/artist/thumb/'.rawurlencode($name).'.jpg']]
                : null;

            return new MockResponse(json_encode(['artists' => $artists], \JSON_THROW_ON_ERROR), ['http_code' => $status]);
        });

        self::getContainer()->set(TheAudioDb::class, new TheAudioDb($client, new MockClock(), '123'));
    }

    private function artist(string $name): Artist
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $artist = $entityManager->getRepository(Artist::class)->findOneBy(['name' => $name]);
        self::assertNotNull($artist);

        return $artist;
    }

    private function coverPath(string $name): string
    {
        $iri = $this->findIriBy(Artist::class, ['name' => $name]);
        self::assertNotNull($iri);

        return $iri.'/cover';
    }

    private function body(): string
    {
        ob_start();
        $this->client->getKernelBrowser()->getResponse()->sendContent();

        return (string) ob_get_clean();
    }
}
