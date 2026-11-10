<?php

namespace App\Library;

/**
 * What the library needs to know about one audio file, read from its tags and the file system.
 */
final readonly class AudioMetadata
{
    public function __construct(
        public string $title,
        public string $artist,
        public string $albumArtist,
        public string $album,
        public ?int $year,
        public ?int $trackNumber,
        public ?int $discNumber,
        public ?string $genre,
        public ?string $lyrics,
        public ?int $durationMs,
        public string $format,
        public string $mimeType,
        public int $fileSize,
        public int $fileModifiedAt,
        /** Identifies the audio regardless of tags and file name (see TagReader::audioHash()); null when unknown. */
        public ?string $audioHash = null,
    ) {
    }
}
