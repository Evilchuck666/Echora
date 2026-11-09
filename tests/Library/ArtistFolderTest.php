<?php

namespace App\Tests\Library;

use App\Library\ArtistImages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ArtistFolderTest extends TestCase
{
    /**
     * @param list<string> $trackPaths
     */
    #[DataProvider('libraries')]
    public function testTheArtistFolderHoldsEveryAlbumFolder(array $trackPaths, ?string $expected): void
    {
        self::assertSame($expected, ArtistImages::artistFolder($trackPaths));
    }

    /**
     * @return iterable<string, array{list<string>, string|null}>
     */
    public static function libraries(): iterable
    {
        yield 'one album' => [['Abbath/2016 - Abbath/01 - To War.flac'], 'Abbath'];
        yield 'several albums' => [['Accept/1980 - I\'m a Rebel/01.flac', 'Accept/1983 - Balls to the Wall/01.flac'], 'Accept'];
        yield 'deeper' => [['Metal/Accept/1980 - I\'m a Rebel/01.flac', 'Metal/Accept/1983 - Balls to the Wall/01.flac'], 'Metal/Accept'];
        yield 'albums in different artist folders' => [['Accept/Best Of/01.flac', 'Dio/Best Of/01.flac'], null];
        yield 'albums at the root of the library' => [['Accept - Breaker/01.flac', 'Accept - Metal Heart/01.flac'], null];
        yield 'a track at the root of the library' => [['untagged.flac'], null];
        yield 'no albums' => [[], null];
    }
}
