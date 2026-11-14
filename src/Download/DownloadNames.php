<?php

namespace App\Download;

use App\Entity\Album;
use App\Entity\Track;

/**
 * Where a downloaded track or cover is suggested to be saved, as a relative path that works on every system:
 * "Album Artist/2020 - Album/01 - Title.flac" ("1-01 - Title.flac" on albums with several discs). Never the path of
 * the file on the server, which isn't exposed.
 */
final class DownloadNames
{
    /** Characters that Windows, macOS, Linux, Android or iOS refuse in a file name. */
    private const string FORBIDDEN = '/[\x00-\x1f\x7f<>:"\/\\\\|?*]/u';

    private const int MAX_NAME_LENGTH = 120;

    public static function trackPath(Track $track, string $extension, bool $severalDiscs): string
    {
        return self::albumFolder($track->getAlbum()).'/'.self::trackFileName($track, $extension, $severalDiscs);
    }

    public static function trackFileName(Track $track, string $extension, bool $severalDiscs = false): string
    {
        $number = $track->getTrackNumber();
        $prefix = null === $number ? '' : \sprintf('%02d - ', $number);
        if (null !== $number && $severalDiscs) {
            $prefix = \sprintf('%d-%02d - ', $track->getDiscNumber() ?? 1, $number);
        }

        return self::name($prefix.$track->getTitle(), 'Track').'.'.$extension;
    }

    public static function coverPath(Album $album, string $extension): string
    {
        return self::albumFolder($album).'/cover.'.$extension;
    }

    public static function albumFolder(Album $album): string
    {
        $year = $album->getYear();

        return self::name($album->getArtist()->getName(), 'Unknown Artist').'/'.self::name((null === $year ? '' : $year.' - ').$album->getTitle(), 'Unknown Album');
    }

    /**
     * One file or folder name: forbidden characters replaced, no dots or spaces at either end (Windows drops them),
     * and short enough for every file system.
     */
    public static function name(string $name, string $fallback): string
    {
        $name = trim((string) preg_replace(self::FORBIDDEN, '_', $name), " .\t");
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = rtrim(mb_substr($name, 0, self::MAX_NAME_LENGTH), ' .');
        }

        return '' === $name ? $fallback : $name;
    }
}
