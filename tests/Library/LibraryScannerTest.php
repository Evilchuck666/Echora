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
    use WritesId3Tags;

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

    public function testAMovedAndRenamedFileKeepsItsTrack(): void
    {
        $this->scanner()->scan($this->library);
        $id = $this->trackId('Opening');
        $this->move('/Test Artist/2020 - First Album/01 - Opening.flac', '/Elsewhere/Opening (renamed).flac');

        $report = $this->scanner()->scan($this->library);

        self::assertSame([0, 1, 0, 4], [$report->added, $report->moved, $report->removed, $report->unchanged]);
        self::assertSame('Elsewhere/Opening (renamed).flac', $this->track($id)?->getPath());
        self::assertSame(5, $this->entityManager()->getRepository(Track::class)->count());
    }

    public function testAMovedFileWithNewTagsKeepsItsTrackWithTheNewTags(): void
    {
        $this->scanner()->scan($this->library);
        $id = $this->trackId('MP3 Song');
        $moved = $this->library.'/Elsewhere/song.mp3';
        $this->move('/Test Artist/2022 - Second Album/01 - MP3 Song.mp3', '/Elsewhere/song.mp3');
        self::writeId3Tags($moved, ['title' => 'Renamed Song', 'artist' => 'Test Artist', 'band' => 'Test Artist', 'album' => 'Second Album']);

        $report = $this->scanner()->scan($this->library);

        self::assertSame([0, 1, 0], [$report->added, $report->moved, $report->removed]);
        $track = $this->track($id);
        self::assertNotNull($track);
        self::assertSame('Renamed Song', $track->getTitle());
        self::assertSame('Elsewhere/song.mp3', $track->getPath());
    }

    public function testFilesWithTheSameAudioMovedTogetherAreToldApartByTheirTitle(): void
    {
        // Both are one second of silence: same audio, same hash.
        $this->scanner()->scan($this->library);
        $opening = $this->trackId('Opening');
        $second = $this->trackId('Second Song');
        $this->move('/Test Artist/2020 - First Album', '/Test Artist/First Album (Deluxe)');

        $report = $this->scanner()->scan($this->library);

        self::assertSame([0, 2, 0], [$report->added, $report->moved, $report->removed]);
        self::assertSame('Test Artist/First Album (Deluxe)/01 - Opening.flac', $this->track($opening)?->getPath());
        self::assertSame('Test Artist/First Album (Deluxe)/02 - Second Song.flac', $this->track($second)?->getPath());
    }

    public function testACopyIsANewTrackAndTheOriginalStays(): void
    {
        $this->scanner()->scan($this->library);
        $id = $this->trackId('Opening');
        (new Filesystem())->copy($this->library.'/Test Artist/2020 - First Album/01 - Opening.flac', $this->library.'/Elsewhere/Opening.flac');

        $report = $this->scanner()->scan($this->library);

        self::assertSame([1, 0, 0], [$report->added, $report->moved, $report->removed]);
        self::assertSame('Test Artist/2020 - First Album/01 - Opening.flac', $this->track($id)?->getPath());
    }

    public function testATrackScannedWithoutAudioHashIsReplacedWhenItsFileMoves(): void
    {
        $this->scanner()->scan($this->library);
        $this->forgetAudioHashes();
        $this->move('/Test Artist/2020 - First Album/01 - Opening.flac', '/Elsewhere/Opening.flac');

        $report = $this->scanner()->scan($this->library);

        self::assertSame([1, 0, 1], [$report->added, $report->moved, $report->removed]);
    }

    public function testAFullScanGivesEveryTrackItsAudioHash(): void
    {
        $this->scanner()->scan($this->library);
        $this->forgetAudioHashes();

        $this->scanner()->scan($this->library, full: true);

        $this->entityManager()->clear();
        $hashes = array_map(static fn (Track $track): ?string => $track->getAudioHash(), $this->entityManager()->getRepository(Track::class)->findAll());
        self::assertCount(5, $hashes);
        self::assertNotContains(null, $hashes);
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

    /**
     * Moves a file or folder of the library copy, creating the destination folder.
     */
    private function move(string $from, string $to): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir(\dirname($this->library.$to));
        $filesystem->rename($this->library.$from, $this->library.$to);
    }

    private function trackId(string $title): string
    {
        $track = $this->entityManager()->getRepository(Track::class)->findOneBy(['title' => $title]);
        self::assertNotNull($track);

        return $track->getId()->toRfc4122();
    }

    private function track(string $id): ?Track
    {
        $this->entityManager()->clear();

        return $this->entityManager()->find(Track::class, $id);
    }

    /**
     * As if the tracks had been scanned by a version of Echora that didn't compute audio hashes.
     */
    private function forgetAudioHashes(): void
    {
        $this->entityManager()->createQuery('UPDATE '.Track::class.' t SET t.audioHash = NULL')->execute();
        $this->entityManager()->clear();
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
