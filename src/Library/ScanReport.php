<?php

namespace App\Library;

/**
 * What a library scan changed.
 */
final class ScanReport
{
    public int $added = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $removed = 0;
    public int $coversUpdated = 0;

    /** @var array<string, string> Error message per file path (relative to the library) */
    public array $failed = [];
}
