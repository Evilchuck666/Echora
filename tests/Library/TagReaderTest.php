<?php

namespace App\Tests\Library;

use App\Library\TagReader;
use App\Library\UnreadableAudioFile;
use PHPUnit\Framework\TestCase;

final class TagReaderTest extends TestCase
{
    private const string LIBRARY = __DIR__.'/../Fixtures/music';

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

    public function testItRejectsFilesThatAreNotAudio(): void
    {
        $this->expectException(UnreadableAudioFile::class);

        (new TagReader())->read(new \SplFileInfo(self::LIBRARY.'/Test Artist/2020 - First Album/album.nfo'));
    }
}
