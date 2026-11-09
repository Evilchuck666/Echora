<?php

namespace App\Library;

/**
 * Finds the cover image of an album in the folder that holds its audio files.
 */
final class CoverFinder
{
    /** File names tried in this order, case-insensitively. */
    private const array NAMES = ['folder', 'cover', 'front', 'album'];

    public const array MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    /**
     * @return string|null File name of the cover inside $directory, e.g. "folder.jpg"
     */
    public function find(string $directory): ?string
    {
        $files = @scandir($directory);
        if (false === $files) {
            return null;
        }

        $candidates = [];
        foreach ($files as $file) {
            $name = strtolower(pathinfo($file, \PATHINFO_FILENAME));
            $extension = strtolower(pathinfo($file, \PATHINFO_EXTENSION));
            $rank = array_search($name, self::NAMES, true);

            if (false !== $rank && isset(self::MIME_TYPES[$extension]) && is_file($directory.'/'.$file)) {
                $candidates[$rank] ??= $file;
            }
        }

        if ([] === $candidates) {
            return null;
        }

        ksort($candidates);

        return reset($candidates);
    }

    public static function mimeType(string $path): string
    {
        return self::MIME_TYPES[strtolower(pathinfo($path, \PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }
}
