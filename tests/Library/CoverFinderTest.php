<?php

namespace App\Tests\Library;

use App\Library\CoverFinder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class CoverFinderTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/echora-cover-'.bin2hex(random_bytes(4));
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->folder);
    }

    /**
     * @param list<string> $files
     */
    #[DataProvider('folders')]
    public function testItFindsTheCover(array $files, ?string $expected): void
    {
        foreach ($files as $file) {
            touch($this->folder.'/'.$file);
        }

        self::assertSame($expected, (new CoverFinder())->find($this->folder));
    }

    /**
     * @return iterable<string, array{list<string>, string|null}>
     */
    public static function folders(): iterable
    {
        yield 'folder.jpg' => [['01 - Song.flac', 'folder.jpg'], 'folder.jpg'];
        yield 'any case and extension' => [['Cover.PNG'], 'Cover.PNG'];
        yield 'folder beats cover' => [['cover.jpg', 'front.jpg', 'folder.webp'], 'folder.webp'];
        yield 'cover beats front' => [['front.jpg', 'cover.jpeg'], 'cover.jpeg'];
        yield 'other images are ignored' => [['artist.jpg', 'back.jpg', 'scan01.png'], null];
        yield 'other formats are ignored' => [['folder.gif', 'cover.bmp'], null];
        yield 'empty folder' => [[], null];
    }

    public function testAMissingFolderHasNoCover(): void
    {
        self::assertNull((new CoverFinder())->find($this->folder.'/missing'));
    }

    public function testMimeTypes(): void
    {
        self::assertSame('image/jpeg', CoverFinder::mimeType('Artist/Album/folder.JPG'));
        self::assertSame('image/png', CoverFinder::mimeType('Cover.png'));
        self::assertSame('image/webp', CoverFinder::mimeType('front.webp'));
    }
}
