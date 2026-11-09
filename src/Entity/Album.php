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
use App\Repository\AlbumRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * An album, identified by its album artist and title.
 */
#[ORM\Entity(repositoryClass: AlbumRepository::class)]
#[ORM\UniqueConstraint(name: 'album_artist_title', columns: ['artist_id', 'title'])]
#[ApiResource(
    operations: [
        new GetCollection(parameters: [
            'artist' => new QueryParameter(filter: new IriFilter(), property: 'artist', description: 'IRI of the album artist, e.g. /api/artists/{id}'),
            'title' => new QueryParameter(filter: new PartialSearchFilter(), property: 'title', description: 'Part of the album title, case-insensitive'),
            'year' => new QueryParameter(filter: new ExactFilter(), property: 'year', description: 'Release year'),
        ]),
        new Get(),
    ],
    normalizationContext: ['groups' => ['album:read']],
    order: ['year' => 'ASC', 'title' => 'ASC'],
)]
class Album
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['album:read', 'track:read'])]
    private Uuid $id;

    /** Path of the cover image, relative to the music library folder. Never exposed. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $coverPath = null;

    /** File name of the cover downloaded from TheAudioDB, in the media folder; used when the album folder has none. Never exposed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadedCover = null;

    /** When TheAudioDB was last asked for the cover of this album, found or not. Never exposed. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $coverLookedUpAt = null;

    /**
     * Signed URL of the cover image, usable without an Authorization header
     * (e.g. as the src of an <img>). Not stored: filled when the album is serialized.
     */
    #[ApiProperty(description: 'Signed URL of the cover image (from the album folder, or else TheAudioDB), or null when there is none; works without an Authorization header, e.g. as the src of an <img>')]
    #[Groups(['album:read', 'track:read'])]
    private ?string $coverUrl = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        #[Groups(['album:read'])]
        private Artist $artist,
        #[ORM\Column(length: 255)]
        #[Groups(['album:read', 'track:read'])]
        private string $title,
        #[ORM\Column(nullable: true)]
        #[Groups(['album:read', 'track:read'])]
        private ?int $year = null,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArtist(): Artist
    {
        return $this->artist;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(?int $year): void
    {
        $this->year = $year;
    }

    public function getCoverPath(): ?string
    {
        return $this->coverPath;
    }

    public function setCoverPath(?string $coverPath): void
    {
        $this->coverPath = $coverPath;
    }

    public function getDownloadedCover(): ?string
    {
        return $this->downloadedCover;
    }

    public function setDownloadedCover(?string $downloadedCover): void
    {
        $this->downloadedCover = $downloadedCover;
    }

    public function getCoverLookedUpAt(): ?\DateTimeImmutable
    {
        return $this->coverLookedUpAt;
    }

    public function setCoverLookedUpAt(?\DateTimeImmutable $coverLookedUpAt): void
    {
        $this->coverLookedUpAt = $coverLookedUpAt;
    }

    /** Whether the album has a cover to serve, local or downloaded. */
    public function hasCover(): bool
    {
        return null !== $this->coverPath || null !== $this->downloadedCover;
    }

    public function getCoverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function setCoverUrl(?string $coverUrl): void
    {
        $this->coverUrl = $coverUrl;
    }
}
