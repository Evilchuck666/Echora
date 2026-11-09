<?php

namespace App\Tests\Library;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Library\LibraryScanner;
use App\Library\LibraryUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LibraryScannerTest extends KernelTestCase
{
    private const string FIXTURES = __DIR__.'/../Fixtures/music';

    private string $library;

    protected function setUp(): void
    {
        // Work on a copy: some tests change files, and the fixtures must stay intact.
        $this->library = sys_get_temp_dir().'/echora-library-'.bin2hex(random_bytes(4));
        (new Filesystem())->mirror(self::FIXTURES, $this->library);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->library);
        parent::tearDown();
    }

    public function testTheFirstScanImportsEveryAudioFile(): void
    {
        $report = $this->scanner()->scan($this->library);

        self::assertSame(5, $report->added, 'Four tagged files and an untagged one; album.nfo is ignored.');
        self::assertSame([0, 0, 0, []], [$report->updated, $report->unchanged, $report->removed, $report->failed]);

        self::assertSame(
            ['Guest Artist', 'Test Artist', 'Unknown Artist', 'Various Artists'],
            array_map(static fn (Artist $artist): string => $artist->getName(), $this->entityManager()->getRepository(Artist::class)->findBy([], ['name' => 'ASC'])),
        );

        $albums = $this->entityManager()->getRepository(Album::class)->findBy([], ['title' => 'ASC']);
        self::assertSame(
            ['Compilation (Various Artists, 2021)', 'First Album (Test Artist, 2020)', 'Second Album (Test Artist, 2022)', 'Unknown Album (Unknown Artist, -)'],
            array_map(static fn (Album $album): string => \sprintf('%s (%s, %s)', $album->getTitle(), $album->getArtist()->getName(), $album->getYear() ?? '-'), $albums),
        );

        $guestTrack = $this->entityManager()->getRepository(Track::class)->findOneBy(['title' => 'Guest Track']);
        self::assertNotNull($guestTrack);
        self::assertSame('Guest Artist', $guestTrack->getArtist()->getName());
        self::assertSame('Various Artists', $guestTrack->getAlbum()->getArtist()->getName());
        self::assertSame('Various Artists/2021 - Compilation/01 - Guest Track.flac', $guestTrack->getPath());
    }

    public function testRescanningSkipsFilesThatDidNotChange(): void
    {
        $this->scanner()->scan($this->library);
        $report = $this->scanner()->scan($this->library);

        self::assertSame([0, 0, 5, 0], [$report->added, $report->updated, $report->unchanged, $report->removed]);
    }

    public function testChangedFilesAreReadAgain(): void
    {
        $this->scanner()->scan($this->library);
        touch($this->library.'/Test Artist/2020 - First Album/01 - Opening.flac', time() + 60);

        $report = $this->scanner()->scan($this->library);

        self::assertSame([0, 1, 4], [$report->added, $report->updated, $report->unchanged]);
        self::assertSame(5, $this->entityManager()->getRepository(Track::class)->count());
    }

    public function testDeletedFilesAreRemovedTogetherWithTheirEmptyAlbumsAndArtists(): void
    {
        $this->scanner()->scan($this->library);
        (new Filesystem())->remove($this->library.'/Various Artists');

        $report = $this->scanner()->scan($this->library);

        self::assertSame(1, $report->removed);
        self::assertNull($this->entityManager()->getRepository(Album::class)->findOneBy(['title' => 'Compilation']));
        self::assertNull($this->entityManager()->getRepository(Artist::class)->findOneBy(['name' => 'Various Artists']));
        self::assertNull($this->entityManager()->getRepository(Artist::class)->findOneBy(['name' => 'Guest Artist']));
        self::assertNotNull($this->entityManager()->getRepository(Artist::class)->findOneBy(['name' => 'Test Artist']));
    }

    public function testCoversAreFoundInTheAlbumFolders(): void
    {
        $report = $this->scanner()->scan($this->library);

        self::assertSame(2, $report->coversUpdated);
        self::assertSame([
            'Compilation' => null,
            'First Album' => 'Test Artist/2020 - First Album/folder.jpg',
            'Second Album' => 'Test Artist/2022 - Second Album/Cover.PNG',
            'Unknown Album' => null,
        ], $this->coverPaths());
    }

    public function testCoversAddedOrRemovedLaterArePickedUpWithoutTouchingTheAudio(): void
    {
        $this->scanner()->scan($this->library);
        copy(self::FIXTURES.'/Test Artist/2020 - First Album/folder.jpg', $this->library.'/Various Artists/2021 - Compilation/cover.jpg');
        unlink($this->library.'/Test Artist/2022 - Second Album/Cover.PNG');

        $report = $this->scanner()->scan($this->library);

        self::assertSame([0, 5, 2], [$report->updated, $report->unchanged, $report->coversUpdated]);
        self::assertSame('Various Artists/2021 - Compilation/cover.jpg', $this->coverPaths()['Compilation']);
        self::assertNull($this->coverPaths()['Second Album']);
    }

    public function testAFullScanReadsEveryFileAgain(): void
    {
        $this->scanner()->scan($this->library);

        $report = $this->scanner()->scan($this->library, full: true);

        self::assertSame([0, 5, 0], [$report->added, $report->updated, $report->unchanged]);
    }

    public function testUnreadableFilesAreReportedAndSkipped(): void
    {
        file_put_contents($this->library.'/Test Artist/broken.flac', 'this is not audio');

        $report = $this->scanner()->scan($this->library);

        self::assertSame(5, $report->added);
        self::assertArrayHasKey('Test Artist/broken.flac', $report->failed);
    }

    public function testAnEmptyFolderNeverWipesTheLibrary(): void
    {
        $this->scanner()->scan($this->library);
        $emptyFolder = $this->library.'/empty';
        mkdir($emptyFolder);

        try {
            $this->scanner()->scan($emptyFolder);
            self::fail('Scanning an empty folder must fail when the library has tracks.');
        } catch (LibraryUnavailable) {
        }

        self::assertSame(5, $this->entityManager()->getRepository(Track::class)->count(), 'Nothing was removed.');
    }

    public function testAMissingFolderIsReported(): void
    {
        $this->expectException(LibraryUnavailable::class);

        $this->scanner()->scan($this->library.'/does-not-exist');
    }

    /**
     * @return array<string, string|null> Cover path by album title
     */
    private function coverPaths(): array
    {
        $this->entityManager()->clear();
        $paths = [];
        foreach ($this->entityManager()->getRepository(Album::class)->findBy([], ['title' => 'ASC']) as $album) {
            $paths[$album->getTitle()] = $album->getCoverPath();
        }

        return $paths;
    }

    private function scanner(): LibraryScanner
    {
        return self::getContainer()->get(LibraryScanner::class);
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}
