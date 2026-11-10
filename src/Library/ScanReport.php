<?php

namespace App\Library;

/**
 * What a library scan changed.
 */
final class ScanReport
{
    public int $added = 0;

    /** Files moved or renamed (found again by their audio): their tracks were kept, with the new path. */
    public int $moved = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $removed = 0;
    public int $coversUpdated = 0;
    public int $coversDownloaded = 0;
    public int $artistImagesUpdated = 0;
    public int $artistImagesDownloaded = 0;

    /** Why TheAudioDB lookups (album covers, artist images) stopped during this scan, if they did. */
    public ?string $theAudioDbFailure = null;

    /** @var array<string, string> Error message per file path (relative to the library) */
    public array $failed = [];
}
