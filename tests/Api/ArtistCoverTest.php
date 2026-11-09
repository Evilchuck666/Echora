<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Artist;
use App\Library\LibraryScanner;
use App\Library\ScanReport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The fixtures (tests/Fixtures/music) give one artist of each kind: Test Artist has artist.jpg
 * and Folder.PNG in its folder, Various Artists has a folder without image, Unknown Artist's
 * album sits at the root of the library, and Guest Artist only appears on a track.
 */
final class ArtistCoverTest extends ApiTestCase
{
    use CreatesUsers;
    use FakesTheAudioDb;

    protected static ?bool $alwaysBootKernel = true;

    private Client $client;
    private string $token;

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
        $this->fakeTheAudioDb(artists: ['Guest Artist']);

        $report = $this->scan();

        sort($this->artistSearches);
        self::assertSame(['Guest Artist', 'Unknown Artist', 'Various Artists'], $this->artistSearches, 'Test Artist has an image of its own.');
        self::assertSame(1, $report->artistImagesDownloaded);
        self::assertNotNull($this->artist('Guest Artist')->getDownloadedImage());

        $this->client->request('GET', $this->coverPath('Guest Artist'), ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([16, 16], \array_slice((array) getimagesizefromstring($this->body()), 0, 2));
    }

    public function testArtistsAreNotLookedUpAgainBeforeAMonth(): void
    {
        $this->fakeTheAudioDb(artists: ['Guest Artist']);
        $this->scan();
        $this->artistSearches = [];

        $this->scan();

        self::assertSame([], $this->artistSearches);
    }

    public function testTheRateLimitStopsTheLookupsButNotTheScan(): void
    {
        $this->fakeTheAudioDb(status: 429);

        $report = $this->scan();

        // Album covers are looked up first: the first 429 stops the artist lookups too.
        self::assertCount(1, [...$this->albumSearches, ...$this->artistSearches], 'No more requests after a 429.');
        self::assertStringContainsString('429', (string) $report->theAudioDbFailure);
        self::assertSame('Test Artist/artist.jpg', $this->artist('Test Artist')->getImagePath(), 'Local images are still found.');
    }

    public function testOfflineScansDontContactTheAudioDb(): void
    {
        $this->fakeTheAudioDb(artists: ['Guest Artist']);

        self::getContainer()->get(LibraryScanner::class)->scan(download: false);

        self::assertSame([], [...$this->albumSearches, ...$this->artistSearches]);
    }

    private function scan(): ScanReport
    {
        // Scans tests/Fixtures/music (MUSIC_LIBRARY_PATH in .env.test).
        return self::getContainer()->get(LibraryScanner::class)->scan();
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
