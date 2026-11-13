<?php

namespace App\Tests\Command;

use App\Entity\LibraryScan;
use App\Library\LibraryScans;
use App\Repository\LibraryScanRepository;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * app:library:scan scans at once, and records its scan like those asked for through the API.
 */
final class LibraryScanCommandTest extends KernelTestCase
{
    public function testAScanFromTheConsoleIsRecordedWithItsReport(): void
    {
        $tester = $this->command();

        $tester->execute(['--offline' => true]);

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/Added\s+5/', $tester->getDisplay());
        $scan = $this->latest();
        self::assertSame(['finished', 'console', 5], [$scan->getStatus()->value, $scan->getTrigger()->value, $scan->getAdded()]);
    }

    public function testAMissingMusicFolderFailsTheScan(): void
    {
        $tester = $this->command();

        self::assertSame(Command::FAILURE, $tester->execute(['directory' => '/nowhere/music']));

        self::assertStringContainsString('does not exist', $tester->getDisplay());
        self::assertSame('failed', $this->latest()->getStatus()->value);
        self::assertStringContainsString('/nowhere/music', (string) $this->latest()->getError());
    }

    public function testTheNightlyScanStepsAsideForAScanAlreadyQueued(): void
    {
        self::bootKernel();
        self::getContainer()->get(LibraryScans::class)->request(false);

        $scheduled = $this->command();
        self::assertSame(Command::SUCCESS, $scheduled->execute(['--scheduled' => true]), 'Not an error for the nightly job.');
        self::assertStringContainsString('already queued', $scheduled->getDisplay());

        self::assertSame(Command::FAILURE, $this->command()->execute([]), 'But an error for someone at the console.');
        self::assertCount(1, self::getContainer()->get(LibraryScanRepository::class)->findAll());
    }

    private function command(): CommandTester
    {
        return new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:library:scan'));
    }

    private function latest(): LibraryScan
    {
        $scan = self::getContainer()->get(LibraryScanRepository::class)->findLatest();
        self::assertInstanceOf(LibraryScan::class, $scan);

        return $scan;
    }
}
