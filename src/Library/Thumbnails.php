<?php

namespace App\Library;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Makes small square JPEG thumbnails of images, and keeps them in the media folder.
 *
 * A thumbnail is made the first time it is asked for, then served from disk. Its file name
 * holds a hash of the source's path, size and modification time, so a replaced image gets a
 * new thumbnail, and the older thumbnails of the same item are removed then.
 */
final readonly class Thumbnails
{
    /** Pixels a side: sharp at 150 CSS pixels on high-density screens. Smaller images aren't enlarged. */
    public const int SIZE = 300;

    private const int JPEG_QUALITY = 82;

    /** Decoding needs about 4 bytes a pixel: refuse images that would take more than ~200 MB. */
    private const int MAX_SOURCE_PIXELS = 50_000_000;

    private string $directory;

    public function __construct(
        #[Autowire('%app.media_dir%')]
        string $mediaDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
        $this->directory = $mediaDir.'/thumbnails';
    }

    /**
     * @param string $source Absolute path of the image (JPEG, PNG or WebP)
     * @param string $name   Identifies the item, e.g. "artist-{id}": its older thumbnails are removed
     *
     * @return string Absolute path of the thumbnail
     *
     * @throws UnreadableImage
     */
    public function of(string $source, string $name): string
    {
        $stat = @stat($source) ?: throw new UnreadableImage(\sprintf('"%s" cannot be read.', $source));
        $file = \sprintf('%s/%s-%s.jpg', $this->directory, $name, substr(hash('xxh128', $source."\0".$stat['size']."\0".$stat['mtime']."\0".self::SIZE), 0, 16));

        if (!is_file($file)) {
            $this->filesystem->mkdir($this->directory);
            // Written next to its final name, then renamed: a request never serves half a file.
            $this->filesystem->dumpFile($file, $this->resize($source));
            foreach (glob(\sprintf('%s/%s-*.jpg', $this->directory, $name)) ?: [] as $older) {
                if ($older !== $file) {
                    $this->filesystem->remove($older);
                }
            }
        }

        return $file;
    }

    /**
     * Absolute path of a thumbnail made by of(), from its file name, if it is still there.
     */
    public function file(string $fileName): ?string
    {
        $file = $this->directory.'/'.basename($fileName);

        return is_file($file) ? $file : null;
    }

    /**
     * Removes a thumbnail made by of(), from its file name.
     */
    public function remove(?string $fileName): void
    {
        if (null !== $fileName) {
            $this->filesystem->remove($this->directory.'/'.basename($fileName));
        }
    }

    /**
     * @throws UnreadableImage
     */
    private function resize(string $source): string
    {
        $size = @getimagesize($source);
        if (false === $size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > self::MAX_SOURCE_PIXELS) {
            throw new UnreadableImage(\sprintf('"%s" is not an image that can be resized.', $source));
        }

        $content = @file_get_contents($source);
        $image = false === $content ? false : @imagecreatefromstring($content);
        if (false === $image) {
            throw new UnreadableImage(\sprintf('"%s" cannot be decoded.', $source));
        }

        // The largest centred square, scaled down to SIZE.
        [$width, $height] = [imagesx($image), imagesy($image)];
        $side = min($width, $height);
        $target = min(self::SIZE, $side);
        $thumbnail = imagecreatetruecolor($target, $target);
        \assert(false !== $thumbnail);
        // Transparent areas (PNG, WebP) become black, as JPEG has no transparency.
        imagefill($thumbnail, 0, 0, (int) imagecolorallocate($thumbnail, 0, 0, 0));
        imagecopyresampled($thumbnail, $image, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $target, $target, $side, $side);

        ob_start();
        imagejpeg($thumbnail, null, self::JPEG_QUALITY);

        return (string) ob_get_clean();
    }
}
