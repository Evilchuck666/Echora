<?php

namespace App\Library;

/**
 * Reads the tags of FLAC and MP3 files with getID3.
 *
 * FLAC (Vorbis comments) and MP3 (ID3v2) name some fields differently, e.g. the album
 * artist is "albumartist" in FLAC and "band" (TPE2) in MP3; both are handled here.
 */
final class TagReader
{
    public const string UNKNOWN_ARTIST = 'Unknown Artist';
    public const string UNKNOWN_ALBUM = 'Unknown Album';

    private const array SUPPORTED_FORMATS = ['flac', 'mp3'];

    private readonly \getID3 $getId3;

    public function __construct()
    {
        $this->getId3 = new \getID3();
        $this->getId3->option_save_attachments = false;
    }

    /**
     * @throws UnreadableAudioFile
     */
    public function read(\SplFileInfo $file): AudioMetadata
    {
        $info = $this->getId3->analyze($file->getPathname());

        if (isset($info['error'])) {
            throw new UnreadableAudioFile(\sprintf('Cannot read "%s": %s', $file->getFilename(), implode(' ', $this->strings($info['error']))));
        }

        $format = $info['fileformat'] ?? null;
        if (!\is_string($format) || !\in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new UnreadableAudioFile(\sprintf('"%s" is not a FLAC or MP3 file.', $file->getFilename()));
        }

        \getid3_lib::CopyTagsToComments($info);
        $comments = $info['comments'] ?? [];
        $comments = \is_array($comments) ? $comments : [];

        $artist = $this->first($comments, 'artist') ?? $this->first($comments, 'albumartist', 'band') ?? self::UNKNOWN_ARTIST;
        $mimeType = $info['mime_type'] ?? null;
        $playtime = $info['playtime_seconds'] ?? null;

        return new AudioMetadata(
            title: $this->first($comments, 'title') ?? $file->getBasename('.'.$file->getExtension()),
            artist: $artist,
            albumArtist: $this->first($comments, 'albumartist', 'band', 'album_artist') ?? $artist,
            album: $this->first($comments, 'album') ?? self::UNKNOWN_ALBUM,
            year: $this->year($this->first($comments, 'date', 'year', 'originalyear')),
            trackNumber: $this->number($this->first($comments, 'track_number', 'tracknumber')),
            discNumber: $this->number($this->first($comments, 'discnumber', 'part_of_a_set')),
            genre: $this->first($comments, 'genre'),
            lyrics: $this->lyrics($this->first($comments, 'lyrics', 'unsyncedlyrics', 'unsynchronised_lyric')),
            durationMs: is_numeric($playtime) ? (int) round((float) $playtime * 1000) : null,
            format: $format,
            mimeType: \is_string($mimeType) ? $mimeType : ('flac' === $format ? 'audio/flac' : 'audio/mpeg'),
            fileSize: (int) $file->getSize(),
            fileModifiedAt: (int) $file->getMTime(),
        );
    }

    /**
     * First non-empty value of the first tag that has one.
     *
     * @param array<mixed> $comments
     */
    private function first(array $comments, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            foreach ($this->strings($comments[$key] ?? []) as $value) {
                $value = trim($value);
                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        return array_values(array_filter($values, \is_string(...)));
    }

    /**
     * "2016", "2016-05-20" → 2016.
     */
    private function year(?string $date): ?int
    {
        return null !== $date && 1 === preg_match('/^(\d{4})/', $date, $matches) ? (int) $matches[1] : null;
    }

    /**
     * Unsynchronised lyrics: FLAC "LYRICS" / "UNSYNCEDLYRICS", MP3 "USLT". Line breaks normalised to \n.
     */
    private function lyrics(?string $lyrics): ?string
    {
        return null === $lyrics ? null : str_replace(["\r\n", "\r"], "\n", $lyrics);
    }

    /**
     * "01", "3/12" → 1, 3.
     */
    private function number(?string $value): ?int
    {
        if (null === $value || 1 !== preg_match('/^\s*(\d+)/', $value, $matches)) {
            return null;
        }

        $number = (int) $matches[1];

        return $number > 0 ? $number : null;
    }
}
