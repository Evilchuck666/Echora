<?php

namespace App\Tests\Download;

use App\Download\DownloadNames;
use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Library\AudioMetadata;
use PHPUnit\Framework\TestCase;

final class DownloadNamesTest extends TestCase
{
    public function testNamesWorkOnEverySystem(): void
    {
        self::assertSame('AC_DC', DownloadNames::name('AC/DC', 'x'));
        self::assertSame('What_ The _Live_ Album', DownloadNames::name('What? The "Live" Album', 'x'));
        self::assertSame('Final Countdown', DownloadNames::name('  Final Countdown...  ', 'x'), 'Windows drops dots and spaces at the end.');
        self::assertSame('Unknown', DownloadNames::name('...', 'Unknown'));
        self::assertSame(120, mb_strlen(DownloadNames::name(str_repeat('é', 200), 'x')));
        self::assertSame('Motörhead', DownloadNames::name('Motörhead', 'x'), 'Other letters stay.');
    }

    public function testTracksOfAlbumsWithSeveralDiscsHaveTheDiscInTheirName(): void
    {
        $track = $this->track(trackNumber: 3, discNumber: 2);

        self::assertSame('Accept/1983 - Balls to the Wall/03 - Losers and Winners.flac', DownloadNames::trackPath($track, 'flac', false));
        self::assertSame('Accept/1983 - Balls to the Wall/2-03 - Losers and Winners.flac', DownloadNames::trackPath($track, 'flac', true));
        self::assertSame('Losers and Winners.opus', DownloadNames::trackFileName($this->track(trackNumber: null), 'opus'), 'Without a number.');
    }

    private function track(?int $trackNumber, ?int $discNumber = 1): Track
    {
        $artist = new Artist('Accept');
        $album = new Album($artist, 'Balls to the Wall', 1983);

        return new Track('x.flac', $album, $artist, new AudioMetadata(
            title: 'Losers and Winners', artist: 'Accept', albumArtist: 'Accept', album: 'Balls to the Wall', year: 1983,
            trackNumber: $trackNumber, discNumber: $discNumber, genre: null, lyrics: null, durationMs: 1000,
            format: 'flac', mimeType: 'audio/flac', fileSize: 1, fileModifiedAt: 0,
        ));
    }
}
