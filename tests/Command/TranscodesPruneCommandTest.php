<?php

namespace App\Tests\Command;

use App\Entity\Track;
use App\Library\LibraryFiles;
use App\Library\LibraryScanner;
use App\Library\OpusTranscoder;
use App\Repository\TrackRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class TranscodesPruneCommandTest extends KernelTestCase
{
    private string $directory;
    private Application $application;

    protected function setUp(): void
    {
        $this->application = new Application(self::bootKernel());
        $this->directory = self::getContainer()->getParameter('app.media_dir').'/transcodes';
        (new Filesystem())->remove($this->directory);
        self::getContainer()->get(LibraryScanner::class)->scan();
    }

    public function testOpusFilesNotPlayedForTheGivenDaysAndThoseOfRemovedTracksAreDeleted(): void
    {
        $tracks = self::getContainer()->get(TrackRepository::class);
        $opening = $tracks->findOneBy(['title' => 'Opening']);
        $second = $tracks->findOneBy(['title' => 'Second Song']);
        self::assertInstanceOf(Track::class, $opening);
        self::assertInstanceOf(Track::class, $second);

        $transcoder = self::getContainer()->get(OpusTranscoder::class);
        $played = $transcoder->transcode($this->source($opening), $opening->getId()->toRfc4122());
        $forgotten = $transcoder->transcode($this->source($second), $second->getId()->toRfc4122());
        touch($forgotten, time() - 20 * 86400, time() - 10 * 86400);
        $removedTrack = $this->directory.'/track-01a1c051-0000-7000-8000-000000000000-0123456789abcdef.opus';
        copy($played, $removedTrack);

        $tester = new CommandTester($this->application->find('app:transcodes:prune'));
        $tester->execute(['--days' => '7']);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Deleted 2 Opus files.', $tester->getDisplay());
        self::assertFileExists($played);
        self::assertFileDoesNotExist($forgotten);
        self::assertFileDoesNotExist($removedTrack);
    }

    public function testANegativeNumberOfDaysIsRejected(): void
    {
        $tester = new CommandTester($this->application->find('app:transcodes:prune'));

        self::assertSame(Command::INVALID, $tester->execute(['--days' => '-1']));
    }

    private function source(Track $track): string
    {
        $path = self::getContainer()->get(LibraryFiles::class)->path($track->getPath());
        self::assertNotNull($path);

        return $path;
    }
}
