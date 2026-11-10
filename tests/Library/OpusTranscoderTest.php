<?php

namespace App\Tests\Library;

use App\Library\OpusTranscoder;
use App\Library\TranscodingFailed;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class OpusTranscoderTest extends TestCase
{
    private const string FLAC = __DIR__.'/../Fixtures/music/Test Artist/2020 - First Album/01 - Opening.flac';
    private const string TRACK_ID = '01a1c051-0000-7000-8000-000000000001';
    private const string OTHER_TRACK_ID = '01a1c051-0000-7000-8000-000000000002';

    private string $folder;
    private OpusTranscoder $transcoder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/echora-transcodes-'.bin2hex(random_bytes(4));
        mkdir($this->folder);
        $this->transcoder = new OpusTranscoder($this->folder.'/media', new LockFactory(new InMemoryStore()));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->folder);
    }

    public function testAFlacFileBecomesOggOpusWithItsTags(): void
    {
        $opus = $this->transcoder->transcode($this->source(), self::TRACK_ID);

        $content = (string) file_get_contents($opus);
        self::assertStringStartsWith('OggS', $content);
        self::assertStringContainsString('OpusHead', $content);
        self::assertStringContainsStringIgnoringCase('title=Opening', $content, 'The tags of the FLAC file are kept.');
        self::assertStringStartsWith($this->folder.'/media/transcodes/track-'.self::TRACK_ID.'-', $opus);
    }

    public function testATrackIsConvertedOnce(): void
    {
        $source = $this->source();
        $first = $this->transcoder->transcode($source, self::TRACK_ID);
        file_put_contents($first, 'cached');

        $second = $this->transcoder->transcode($source, self::TRACK_ID);

        self::assertSame($first, $second);
        self::assertSame('cached', file_get_contents($second), 'The cached file was not converted again.');
    }

    public function testAChangedFlacFileIsConvertedAgainAndTheOldVersionIsRemoved(): void
    {
        $source = $this->source();
        $old = $this->transcoder->transcode($source, self::TRACK_ID);
        $other = $this->transcoder->transcode($this->source('other.flac'), self::OTHER_TRACK_ID);

        touch($source, time() - 1000);
        $new = $this->transcoder->transcode($source, self::TRACK_ID);

        self::assertNotSame($old, $new);
        self::assertFileExists($new);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($other, 'Only the versions of the same track are removed.');
    }

    public function testUsingAFileUpdatesItsAccessTimeButNotItsModificationTime(): void
    {
        $source = $this->source();
        $opus = $this->transcoder->transcode($source, self::TRACK_ID);
        touch($opus, 1_000_000, 2_000_000);

        $this->transcoder->transcode($source, self::TRACK_ID);

        clearstatcache();
        self::assertSame(1_000_000, filemtime($opus), 'Caddy derives the ETag from it: players resuming a download would start over.');
        self::assertEqualsWithDelta(time(), fileatime($opus), 5);
    }

    public function testAFileThatIsNotFlacCannotBeConvertedAndLeavesNothingBehind(): void
    {
        $source = $this->folder.'/broken.flac';
        file_put_contents($source, 'not audio');

        try {
            $this->transcoder->transcode($source, self::TRACK_ID);
            self::fail('A broken file cannot be converted.');
        } catch (TranscodingFailed) {
        }

        self::assertSame([], glob($this->folder.'/media/transcodes/*'));
    }

    public function testAMissingFileCannotBeConverted(): void
    {
        $this->expectException(TranscodingFailed::class);

        $this->transcoder->transcode($this->folder.'/missing.flac', self::TRACK_ID);
    }

    public function testPruneRemovesUnusedFilesFilesOfRemovedTracksAndLeftovers(): void
    {
        $recent = $this->transcoder->transcode($this->source(), self::TRACK_ID);
        $unused = $this->transcoder->transcode($this->source('unused.flac'), self::OTHER_TRACK_ID);
        touch($unused, time() - 100 * 86400, time() - 40 * 86400);
        $removedTrack = $this->transcoder->transcode($this->source('removed.flac'), '01a1c051-0000-7000-8000-000000000003');
        $oldLeftover = $recent.'.1a2b3c4d.part';
        $runningConversion = $recent.'.5e6f7a8b.part';
        touch($oldLeftover, time() - 2 * 3600);
        touch($runningConversion);

        $removed = $this->transcoder->prune(new \DateTimeImmutable('-30 days'), [self::TRACK_ID, self::OTHER_TRACK_ID]);

        self::assertSame(3, $removed);
        self::assertFileExists($recent);
        self::assertFileExists($runningConversion);
        self::assertFileDoesNotExist($unused);
        self::assertFileDoesNotExist($removedTrack);
        self::assertFileDoesNotExist($oldLeftover);
    }

    public function testPruneWithoutAnyFileRemovesNothing(): void
    {
        self::assertSame(0, $this->transcoder->prune(new \DateTimeImmutable(), [self::TRACK_ID]));
    }

    /**
     * A copy of a fixture FLAC file, which tests may change.
     */
    private function source(string $name = 'source.flac'): string
    {
        $path = $this->folder.'/'.$name;
        copy(self::FLAC, $path);

        return $path;
    }
}
