<?php

namespace App\Tests\Library;

/**
 * Rewrites the ID3v2 tag of an MP3 file like a tag editor would: the audio frames stay as they are.
 */
trait WritesId3Tags
{
    /**
     * @param array<string, string> $tags e.g. ['title' => 'New title']; the other tags are removed
     */
    private static function writeId3Tags(string $file, array $tags): void
    {
        $writer = new \getid3_writetags();
        $writer->filename = $file;
        $writer->tagformats = ['id3v2.3'];
        $writer->overwrite_tags = true;
        $writer->tag_encoding = 'UTF-8';
        $writer->tag_data = array_map(static fn (string $value): array => [$value], $tags);

        self::assertTrue($writer->WriteTags(), (string) json_encode($writer->errors));
    }
}
