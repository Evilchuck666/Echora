<?php

namespace App\Library;

/**
 * The library folder is missing or holds no audio files while the database has tracks:
 * most likely the drive isn't mounted, so the scan refuses to remove the whole library.
 */
final class LibraryUnavailable extends \RuntimeException
{
}
