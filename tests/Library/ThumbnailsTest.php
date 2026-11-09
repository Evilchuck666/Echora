<?php

namespace App\Tests\Library;

use App\Library\Thumbnails;
use App\Library\UnreadableImage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class ThumbnailsTest extends TestCase
{
    private string $folder;
    private Thumbnails $thumbnails;

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/echora-thumbnails-'.bin2hex(random_bytes(4));
        mkdir($this->folder);
        $this->thumbnails = new Thumbnails($this->folder.'/media');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->folder);
    }

    public function testALargeImageBecomesASquareJpegOfAtMostTheThumbnailSize(): void
    {
        $thumbnail = $this->thumbnails->of($this->image('wide.png', 600, 400), 'artist-1');

        self::assertSame([Thumbnails::SIZE, Thumbnails::SIZE, \IMAGETYPE_JPEG], \array_slice((array) getimagesize($thumbnail), 0, 3));
    }

    public function testASmallImageIsNotEnlarged(): void
    {
        $thumbnail = $this->thumbnails->of($this->image('small.png', 8, 12), 'artist-1');

        self::assertSame([8, 8], \array_slice((array) getimagesize($thumbnail), 0, 2));
    }

    public function testTheThumbnailIsMadeOnce(): void
    {
        $source = $this->image('photo.png', 400, 400);
        $first = $this->thumbnails->of($source, 'artist-1');
        $madeAt = filemtime($first);
        touch($first, $madeAt - 100);

        $second = $this->thumbnails->of($source, 'artist-1');

        self::assertSame($first, $second);
        self::assertSame($madeAt - 100, filemtime($second), 'The cached thumbnail was not made again.');
    }

    public function testAReplacedImageGetsANewThumbnailAndTheOldOneIsRemoved(): void
    {
        $source = $this->image('photo.png', 400, 400);
        $old = $this->thumbnails->of($source, 'artist-1');
        $other = $this->thumbnails->of($this->image('other.png', 400, 400), 'artist-2');

        $this->image('photo.png', 500, 500);
        touch($source, time() + 10);
        $new = $this->thumbnails->of($source, 'artist-1');

        self::assertNotSame($old, $new);
        self::assertFileDoesNotExist($old);
        self::assertFileExists($other, 'Thumbnails of other items are left alone.');
    }

    public function testAFileThatIsNotAnImageIsRefused(): void
    {
        file_put_contents($this->folder.'/fake.jpg', 'not an image');

        $this->expectException(UnreadableImage::class);
        $this->thumbnails->of($this->folder.'/fake.jpg', 'artist-1');
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function image(string $name, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        \assert(false !== $image);
        imagepng($image, $this->folder.'/'.$name);

        return $this->folder.'/'.$name;
    }
}
