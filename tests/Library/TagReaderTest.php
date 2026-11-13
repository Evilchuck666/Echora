<?php

namespace App\Tests\Library;

use App\Library\ReplayGain;
use App\Library\TagReader;
use App\Library\UnreadableAudioFile;
use PHPUnit\Framework\TestCase;

final class TagReaderTest extends TestCase
{
    use WritesId3Tags;

    private const string LIBRARY = __DIR__.'/../Fixtures/music';
    private const string MP3 = self::LIBRARY.'/Test Artist/2022 - Second Album/01 - MP3 Song.mp3';
    private const string REPLAYGAIN = __DIR__.'/../Fixtures/replaygain';

    private ?string $copy = null;

    protected function tearDown(): void
    {
        if (null !== $this->copy) {
            @unlink($this->copy);
        }
    }

    public function testItReadsVorbisCommentsFromFlac(): void
    {
        $metadata = (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Test Artist/2020 - First Album/01 - Opening.flac'));

        self::assertSame('Opening', $metadata->title);
        self::assertSame('Test Artist', $metadata->artist);
        self::assertSame('Test Artist', $metadata->albumArtist);
        self::assertSame('First Album', $metadata->album);
        self::assertSame(2020, $metadata->year);
        self::assertSame(1, $metadata->trackNumber);
        self::assertSame(1, $metadata->discNumber);
        self::assertSame('Heavy Metal', $metadata->genre);
        self::assertSame("First line of the opening\nSecond line of the opening", $metadata->lyrics);
        self::assertSame(1000, $metadata->durationMs);
        self::assertSame('flac', $metadata->format);
        self::assertSame('audio/flac', $metadata->mimeType);
    }

    public function testItReadsId3TagsFromMp3(): void
    {
        $metadata = (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Test Artist/2022 - Second Album/01 - MP3 Song.mp3'));

        self::assertSame('MP3 Song', $metadata->title);
        self::assertSame('Test Artist', $metadata->albumArtist, 'The album artist of an MP3 is the "band" (TPE2) frame.');
        self::assertSame('Second Album', $metadata->album);
        self::assertSame(2022, $metadata->year);
        self::assertSame(1, $metadata->trackNumber, '"1/1" is track 1.');
        self::assertSame(1, $metadata->discNumber, '"1/1" is disc 1.');
        self::assertSame("First line of the MP3 song\nSecond line of the MP3 song", $metadata->lyrics, 'MP3 lyrics are the USLT frame.');
        self::assertSame('mp3', $metadata->format);
        self::assertSame('audio/mpeg', $metadata->mimeType);
    }

    public function testTheAlbumArtistOfACompilationDiffersFromTheTrackArtist(): void
    {
        $metadata = (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Various Artists/2021 - Compilation/01 - Guest Track.flac'));

        self::assertSame('Guest Artist', $metadata->artist);
        self::assertSame('Various Artists', $metadata->albumArtist);
        self::assertSame(2, $metadata->discNumber);
    }

    public function testUntaggedFilesFallBackToTheFileNameAndUnknownValues(): void
    {
        $metadata = (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Untagged/untagged.flac'));

        self::assertSame('untagged', $metadata->title);
        self::assertSame(TagReader::UNKNOWN_ARTIST, $metadata->artist);
        self::assertSame(TagReader::UNKNOWN_ARTIST, $metadata->albumArtist);
        self::assertSame(TagReader::UNKNOWN_ALBUM, $metadata->album);
        self::assertNull($metadata->year);
        self::assertNull($metadata->trackNumber);
        self::assertNull($metadata->lyrics);
    }

    public function testItReadsReplayGainFromTheVorbisCommentsOfAFlacFile(): void
    {
        $metadata = (new TagReader())->read(new \SplFileInfo(self::REPLAYGAIN.'/replaygain.flac'));

        self::assertEquals(new ReplayGain(trackGain: -6.48, trackPeak: 0.988159, albumGain: -5.92, albumPeak: 0.999969), $metadata->replayGain);
    }

    public function testItReadsReplayGainFromTxxxFramesWhateverTheCaseOfTheirDescription(): void
    {
        // Written REPLAYGAIN_TRACK_GAIN, as foobar2000 and Mp3tag do: getID3 alone only knows the lower case.
        $metadata = (new TagReader())->read(new \SplFileInfo(self::REPLAYGAIN.'/replaygain.mp3'));

        self::assertEquals(new ReplayGain(trackGain: 3.21, trackPeak: 0.501187), $metadata->replayGain);
    }

    public function testFilesWithoutReplayGainHaveNone(): void
    {
        self::assertNull((new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Test Artist/2020 - First Album/01 - Opening.flac'))->replayGain);
        self::assertNull((new TagReader())->read(new \SplFileInfo(self::MP3))->replayGain);
    }

    public function testReplayGainValuesAreReadFromTextAndBrokenOnesIgnored(): void
    {
        self::assertSame(-6.48, ReplayGain::gain('-6.48 dB'));
        self::assertSame(3.21, ReplayGain::gain('+3,21 dB'), 'A decimal comma.');
        self::assertSame(0.0, ReplayGain::gain(0));
        self::assertNull(ReplayGain::gain('loud'));
        self::assertNull(ReplayGain::gain('-99 dB'), 'Out of any sensible range.');
        self::assertSame(0.988159, ReplayGain::peak('0.988159'));
        self::assertNull(ReplayGain::peak('0'));
        self::assertNull(ReplayGain::peak('-1'));
        self::assertNull(ReplayGain::peak(null));
    }

    public function testItRejectsFilesThatAreNotAudio(): void
    {
        $this->expectException(UnreadableAudioFile::class);

        (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Test Artist/2020 - First Album/album.nfo'));
    }

    public function testTheAudioHashOfAFlacFileIsTheMd5StoredByItsEncoder(): void
    {
        $metadata = (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Test Artist/2020 - First Album/01 - Opening.flac'));

        // The MD5 of the decoded audio in STREAMINFO, also shown by "metaflac --show-md5sum".
        self::assertSame('flac:e9ded829730eccd2d0273d7cc06be58c', $metadata->audioHash);
    }

    public function testTheAudioHashOfAnMp3FileIgnoresItsTags(): void
    {
        $file = $this->copyOfTheMp3();
        $before = (new TagReader())->read(new \SplFileInfo($file));

        self::writeId3Tags($file, ['title' => 'Renamed Song', 'artist' => 'Someone Else', 'comment' => str_repeat('A longer tag. ', 100)]);
        $after = (new TagReader())->read(new \SplFileInfo($file));

        self::assertSame('Renamed Song', $after->title);
        self::assertNotSame($before->fileSize, $after->fileSize);
        self::assertNotNull($before->audioHash);
        self::assertStringStartsWith('mp3:', $before->audioHash);
        self::assertSame($before->audioHash, $after->audioHash);
    }

    public function testTheAudioHashOfAnMp3FileChangesWithItsAudio(): void
    {
        $file = $this->copyOfTheMp3();
        $before = (new TagReader())->read(new \SplFileInfo($file));

        $content = (string) file_get_contents($file);
        $middle = intdiv(\strlen($content), 2);
        $content[$middle] = \chr(\ord($content[$middle]) ^ 0xFF);
        file_put_contents($file, $content);

        self::assertNotSame($before->audioHash, (new TagReader())->read(new \SplFileInfo($file))->audioHash);
    }

    private function copyOfTheMp3(): string
    {
        $this->copy = sys_get_temp_dir().'/echora-tags-'.bin2hex(random_bytes(4)).'.mp3';
        copy(self::MP3, $this->copy);

        return $this->copy;
    }
}
