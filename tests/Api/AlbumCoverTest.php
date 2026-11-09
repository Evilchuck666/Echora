<?php

namespace App\Tests\Api;

use ApiPlatform\Test\ApiTestCase;
use ApiPlatform\Test\Client;
use App\Entity\Album;
use App\Library\LibraryScanner;
use App\Library\ScanReport;
use App\Library\SignedUrlGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The fixtures (tests/Fixtures/music) have two albums with a cover in their folder (First Album,
 * Second Album) and two without (Compilation, and Unknown Album for the untagged file).
 */
final class AlbumCoverTest extends ApiTestCase
{
    use CreatesUsers;
    use FakesTheAudioDb;

    protected static ?bool $alwaysBootKernel = true;

    private const string COVER = __DIR__.'/../Fixtures/music/Test Artist/2020 - First Album/folder.jpg';

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

    public function testTheCoverIsServedWithAJwt(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('First Album'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        self::assertResponseHeaderSame('content-length', (string) filesize(self::COVER));
    }

    public function testThePngCoverIsServedAsPng(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('Second Album'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'image/png');
    }

    public function testTheSignedCoverUrlWorksWithoutAuthorizationHeaderAndCanBeCached(): void
    {
        $this->scan();

        $url = $this->coverUrl('First Album');

        $this->client->request('GET', $url);

        self::assertResponseStatusCodeSame(200);
        $cacheControl = (string) $this->client->getResponse()?->getHeaders()['cache-control'][0];
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
    }

    public function testTheSignedCoverUrlStaysTheSameDuringTheDay(): void
    {
        $this->scan();

        $url = $this->coverUrl('First Album');

        self::assertSame($url, $this->coverUrl('First Album'), 'A stable URL lets browsers cache the image.');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame((string) SignedUrlGenerator::coverExpiration()->getTimestamp(), $query['_expiration'] ?? null);
    }

    public function testATamperedSignatureIsRejected(): void
    {
        $this->scan();

        $this->client->request('GET', str_replace('_hash=', '_hash=x', $this->coverUrl('First Album')));

        self::assertResponseStatusCodeSame(401);
    }

    public function testWithoutTokenOrSignatureTheCoverIsNotServed(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('First Album'));

        self::assertResponseStatusCodeSame(401);
    }

    public function testAnAlbumWithoutCoverHasNone(): void
    {
        $this->scan();

        $this->client->request('GET', $this->coverPath('Compilation'), ['auth_bearer' => $this->token]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testCoversMissingFromTheAlbumFoldersAreDownloadedFromTheAudioDb(): void
    {
        $this->fakeTheAudioDb(albums: ['Various Artists / Compilation']);

        $report = $this->scan();

        sort($this->albumSearches);
        self::assertSame(['Unknown Artist / Unknown Album', 'Various Artists / Compilation'], $this->albumSearches, 'The other albums have a cover of their own.');
        self::assertSame(1, $report->coversDownloaded);
        self::assertNull($this->album('Compilation')->getCoverPath());
        self::assertNotNull($this->album('Compilation')->getDownloadedCover());

        $this->client->request('GET', $this->coverUrl('Compilation'));
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'image/jpeg');
        self::assertSame([16, 16], \array_slice((array) getimagesizefromstring($this->body()), 0, 2));
    }

    public function testTracksShowTheDownloadedCoverOfTheirAlbum(): void
    {
        $this->fakeTheAudioDb(albums: ['Various Artists / Compilation']);
        $this->scan();

        $tracks = $this->client->request('GET', '/api/tracks?title=Guest Track', ['auth_bearer' => $this->token])->toArray()['member'];
        self::assertIsArray($tracks);
        self::assertCount(1, $tracks);
        self::assertIsArray($tracks[0]);
        self::assertIsArray($tracks[0]['album']);
        self::assertIsString($tracks[0]['album']['coverUrl']);
        self::assertStringContainsString('/cover?', $tracks[0]['album']['coverUrl']);
    }

    public function testAlbumsAreNotLookedUpAgainBeforeAMonth(): void
    {
        $this->fakeTheAudioDb(albums: ['Various Artists / Compilation']);
        $this->scan();
        $this->albumSearches = [];

        $this->scan();

        self::assertSame([], $this->albumSearches);
    }

    public function testADownloadedCoverThatWentMissingIsDownloadedAgain(): void
    {
        $this->fakeTheAudioDb(albums: ['Various Artists / Compilation']);
        $this->scan();
        $mediaDir = self::getContainer()->getParameter('app.media_dir');
        (new Filesystem())->remove($mediaDir.'/albums');
        $this->albumSearches = [];

        $report = $this->scan();

        self::assertSame(['Various Artists / Compilation'], $this->albumSearches);
        self::assertSame(1, $report->coversDownloaded);
        $this->client->request('GET', $this->coverPath('Compilation'), ['auth_bearer' => $this->token]);
        self::assertResponseStatusCodeSame(200);
    }

    public function testACoverAddedToTheAlbumFolderReplacesTheDownloadedOne(): void
    {
        // Work on a copy of the library, as a cover is added to it.
        $filesystem = new Filesystem();
        $library = sys_get_temp_dir().'/echora-library-'.bin2hex(random_bytes(4));
        $filesystem->mirror(__DIR__.'/../Fixtures/music', $library);

        try {
            $this->fakeTheAudioDb(albums: ['Various Artists / Compilation']);
            $this->scan($library);
            $downloaded = $this->album('Compilation')->getDownloadedCover();
            self::assertNotNull($downloaded);
            $mediaDir = self::getContainer()->getParameter('app.media_dir');
            self::assertFileExists($mediaDir.'/albums/'.$downloaded);

            copy(self::COVER, $library.'/Various Artists/2021 - Compilation/cover.jpg');
            $this->scan($library);

            $album = $this->album('Compilation');
            self::assertSame('Various Artists/2021 - Compilation/cover.jpg', $album->getCoverPath());
            self::assertNull($album->getDownloadedCover());
            self::assertFileDoesNotExist($mediaDir.'/albums/'.$downloaded);
        } finally {
            $filesystem->remove($library);
        }
    }

    public function testTheRateLimitStopsTheLookupsButNotTheScan(): void
    {
        $this->fakeTheAudioDb(status: 429);

        $report = $this->scan();

        self::assertCount(1, $this->albumSearches, 'No more requests after a 429.');
        self::assertSame([], $this->artistSearches);
        self::assertStringContainsString('429', (string) $report->theAudioDbFailure);
        self::assertSame('Test Artist/2020 - First Album/folder.jpg', $this->album('First Album')->getCoverPath(), 'Local covers are still found.');
    }

    /**
     * Scans tests/Fixtures/music (MUSIC_LIBRARY_PATH in .env.test), or a copy. TheAudioDB is off
     * in the test environment unless a test calls fakeTheAudioDb() first.
     */
    private function scan(?string $library = null): ScanReport
    {
        return self::getContainer()->get(LibraryScanner::class)->scan($library);
    }

    private function album(string $title): Album
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $album = $entityManager->getRepository(Album::class)->findOneBy(['title' => $title]);
        self::assertNotNull($album);

        return $album;
    }

    private function body(): string
    {
        ob_start();
        $this->client->getKernelBrowser()->getResponse()->sendContent();

        return (string) ob_get_clean();
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
