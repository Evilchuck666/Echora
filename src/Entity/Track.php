<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\IriFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Library\AudioMetadata;
use App\Repository\TrackRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * An audio file of the library. Its audio is served by GET /api/tracks/{id}/stream, and, for FLAC
 * files, converted to Opus by GET /api/tracks/{id}/stream/opus.
 */
#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(parameters: [
            'album' => new QueryParameter(filter: new IriFilter(), property: 'album', description: 'IRI of the album, e.g. /api/albums/{id}'),
            'artist' => new QueryParameter(filter: new IriFilter(), property: 'artist', description: 'IRI of the track artist, e.g. /api/artists/{id}'),
            'albumArtist' => new QueryParameter(filter: new IriFilter(), property: 'album.artist', description: 'IRI of the album artist, e.g. /api/artists/{id}: every track of their albums, album after album'),
            'title' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title', description: 'Part of the track title, case-insensitive'),
            'genre' => new QueryParameter(filter: new ExactFilter(), property: 'genre', description: 'Genre'),
        ]),
        // Lyrics only come with a single track, to keep lists light.
        new GetCollection(
            uriTemplate: '/favourites/tracks',
            name: 'favourites_tracks',
            description: 'The tracks the user making the request marked as favourites, newest first.',
        ),
        new Get(normalizationContext: ['groups' => ['track:read', 'track:item']]),
    ],
    normalizationContext: ['groups' => ['track:read']],
    // Sorted by App\Doctrine\TrackOrderExtension: album artist, album (year, title), disc and track number.
)]
class Track
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['track:read'])]
    private Uuid $id;

    #[ORM\Column(length: 255)]
    #[Groups(['track:read'])]
    private string $title;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['track:read'])]
    private Album $album;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['track:read'])]
    private Artist $artist;

    #[ORM\Column(nullable: true)]
    #[Groups(['track:read'])]
    private ?int $trackNumber;

    #[ORM\Column(nullable: true)]
    #[Groups(['track:read'])]
    private ?int $discNumber;

    /** Length in milliseconds, when known. */
    #[ORM\Column(nullable: true)]
    #[Groups(['track:read'])]
    private ?int $durationMs;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['track:read'])]
    private ?string $genre;

    /** Unsynchronised lyrics from the tags, lines separated by \n. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['track:item'])]
    private ?string $lyrics;

    /** Container format: "flac" or "mp3". */
    #[ORM\Column(length: 16)]
    #[Groups(['track:read'])]
    private string $format;

    #[ORM\Column(length: 64)]
    #[Groups(['track:read'])]
    private string $mimeType;

    /** Size of the audio file in bytes. */
    #[ORM\Column(type: 'bigint')]
    #[Groups(['track:read'])]
    private int $fileSize;

    /**
     * Last modification time of the file (Unix timestamp), to detect changes on rescans.
     * Not exposed: like the path, it describes the server's disk, not the music.
     */
    #[ORM\Column(type: 'bigint')]
    private int $fileModifiedAt;

    /**
     * Hash of the audio alone, without the tags (TagReader::audioHash()): a file moved or renamed, even with
     * new tags, keeps its track, so favorites and playlists that point to the track keep working.
     * Null for tracks scanned before it existed (until app:library:scan --full) or when it can't be computed.
     * Not exposed: it only serves the scanner.
     */
    #[ORM\Column(length: 48, nullable: true)]
    private ?string $audioHash = null;

    /** ReplayGain tags of the file (dB and linear peaks); exposed together by getReplayGain(). */
    #[ORM\Column(nullable: true)]
    private ?float $replayGainTrackGain = null;

    #[ORM\Column(nullable: true)]
    private ?float $replayGainTrackPeak = null;

    #[ORM\Column(nullable: true)]
    private ?float $replayGainAlbumGain = null;

    #[ORM\Column(nullable: true)]
    private ?float $replayGainAlbumPeak = null;

    /**
     * Signed URL of the audio, valid for a few hours, usable without an Authorization header
     * (e.g. as the src of an <audio> element). Not stored: filled when the track is serialized.
     */
    #[ApiProperty(description: 'Signed URL of the audio, valid for a few hours; works without an Authorization header, e.g. as the src of an <audio> element')]
    #[Groups(['track:read'])]
    private ?string $streamUrl = null;

    /**
     * Signed URL of the audio converted to Opus (App\Library\OpusTranscoder), for FLAC tracks only. Not stored.
     */
    #[ApiProperty(description: 'Signed URL of the audio converted to Opus (Ogg, 256 kbit/s), to save bandwidth. Left out for MP3 tracks, which are only served as they are. Valid and usable like streamUrl. The first request for a track waits for the conversion (a few seconds)')]
    #[Groups(['track:read'])]
    private ?string $opusStreamUrl = null;

    /**
     * Whether the user the request is authenticated as has this track among their favourites. Not stored: filled
     * when the track is serialized (App\Serializer\FavouriteNormalizer).
     */
    #[ApiProperty(description: 'Whether this track is one of the favourites of the user making the request (PUT or DELETE /api/tracks/{id}/favourite)')]
    #[Groups(['track:read'])]
    private bool $favourite = false;

    /**
     * @param string $path Path of the audio file, relative to the music library folder. Never exposed.
     */
    public function __construct(
        #[ORM\Column(type: 'text', unique: true)]
        private string $path,
        Album $album,
        Artist $artist,
        AudioMetadata $metadata,
    ) {
        $this->id = Uuid::v7();
        $this->update($album, $artist, $metadata);
    }

    public function update(Album $album, Artist $artist, AudioMetadata $metadata): void
    {
        $this->album = $album;
        $this->artist = $artist;
        $this->title = $metadata->title;
        $this->trackNumber = $metadata->trackNumber;
        $this->discNumber = $metadata->discNumber;
        $this->durationMs = $metadata->durationMs;
        $this->genre = $metadata->genre;
        $this->lyrics = $metadata->lyrics;
        $this->format = $metadata->format;
        $this->mimeType = $metadata->mimeType;
        $this->fileSize = $metadata->fileSize;
        $this->fileModifiedAt = $metadata->fileModifiedAt;
        $this->audioHash = $metadata->audioHash;
        $this->replayGainTrackGain = $metadata->replayGain?->trackGain;
        $this->replayGainTrackPeak = $metadata->replayGain?->trackPeak;
        $this->replayGainAlbumGain = $metadata->replayGain?->albumGain;
        $this->replayGainAlbumPeak = $metadata->replayGain?->albumPeak;
    }

    /**
     * The ReplayGain tags of the file, those it has, or null without any.
     *
     * @return array{trackGain?: float, trackPeak?: float, albumGain?: float, albumPeak?: float}|null
     */
    #[ApiProperty(
        description: 'Loudness normalization, from the ReplayGain tags of the file, with only the values it has; left out without any. '
            .'trackGain and albumGain are decibels to apply (play the track at 10^(gain/20) of its volume: albumGain keeps the differences within an album, trackGain evens out every track); '
            .'trackPeak and albumPeak are the loudest samples (1.0 = full scale): never apply more than 1/peak, or the sound clips',
        schema: [
            'type' => 'object',
            'properties' => [
                'trackGain' => ['type' => 'number', 'example' => -6.48],
                'trackPeak' => ['type' => 'number', 'example' => 0.988159],
                'albumGain' => ['type' => 'number', 'example' => -5.92],
                'albumPeak' => ['type' => 'number', 'example' => 0.999969],
            ],
        ],
    )]
    #[Groups(['track:read'])]
    public function getReplayGain(): ?array
    {
        $replayGain = array_filter([
            'trackGain' => $this->replayGainTrackGain,
            'trackPeak' => $this->replayGainTrackPeak,
            'albumGain' => $this->replayGainAlbumGain,
            'albumPeak' => $this->replayGainAlbumPeak,
        ], static fn (?float $value): bool => null !== $value);

        return [] === $replayGain ? null : $replayGain;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * The file was moved or renamed in the library: the track, and everything that points to it, stays.
     */
    public function moveTo(string $path): void
    {
        $this->path = $path;
    }

    public function getAudioHash(): ?string
    {
        return $this->audioHash;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAlbum(): Album
    {
        return $this->album;
    }

    public function getArtist(): Artist
    {
        return $this->artist;
    }

    public function getTrackNumber(): ?int
    {
        return $this->trackNumber;
    }

    public function getDiscNumber(): ?int
    {
        return $this->discNumber;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getGenre(): ?string
    {
        return $this->genre;
    }

    public function getLyrics(): ?string
    {
        return $this->lyrics;
    }

    /**
     * Serialized as "hasLyrics" in lists, where the lyrics themselves are left out.
     */
    #[ApiProperty(description: 'Whether GET /api/tracks/{id} returns lyrics for this track')]
    #[Groups(['track:read'])]
    public function getHasLyrics(): bool
    {
        return null !== $this->lyrics;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function getFileModifiedAt(): int
    {
        return $this->fileModifiedAt;
    }

    public function getStreamUrl(): ?string
    {
        return $this->streamUrl;
    }

    public function setStreamUrl(string $streamUrl): void
    {
        $this->streamUrl = $streamUrl;
    }

    /**
     * Only lossless files are converted: an MP3 converted to Opus would lose quality twice.
     */
    public function isAvailableInOpus(): bool
    {
        return 'flac' === $this->format;
    }

    public function getOpusStreamUrl(): ?string
    {
        return $this->opusStreamUrl;
    }

    public function setOpusStreamUrl(?string $opusStreamUrl): void
    {
        $this->opusStreamUrl = $opusStreamUrl;
    }

    public function isFavourite(): bool
    {
        return $this->favourite;
    }

    public function setFavourite(bool $favourite): void
    {
        $this->favourite = $favourite;
    }
}
