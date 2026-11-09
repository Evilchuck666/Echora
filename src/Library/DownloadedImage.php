<?php

namespace App\Library;

/**
 * An image downloaded from TheAudioDB, not yet saved.
 */
final readonly class DownloadedImage
{
    public function __construct(
        public string $content,
        /** "jpg", "png" or "webp", from the response's Content-Type. */
        public string $extension,
    ) {
    }
}
