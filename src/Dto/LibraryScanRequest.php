<?php

namespace App\Dto;

/**
 * Body of POST /api/library/scan.
 */
final readonly class LibraryScanRequest
{
    public function __construct(
        /** Read every file again, even unchanged ones (after an update of Echora that reads more tags). */
        public bool $full = false,
    ) {
    }
}
