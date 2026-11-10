<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\QueryParameter;
use App\Repository\ArtistRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * A performer: the album artist of an album or the artist of a track.
 */
#[ORM\Entity(repositoryClass: ArtistRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(parameters: [
            'name' => new QueryParameter(filter: new PartialSearchFilter(), property: 'name', description: 'Part of the artist name, case-insensitive'),
        ]),
        new GetCollection(
            uriTemplate: '/favourites/artists',
            name: 'favourites_artists',
            description: 'The artists the user making the request marked as favourites, newest first.',
        ),
        new Get(),
    ],
    normalizationContext: ['groups' => ['artist:read']],
    order: ['name' => 'ASC'],
)]
class Artist
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['artist:read', 'album:read', 'track:read'])]
    private Uuid $id;

    /** Path of the artist's image in the artist folder, relative to the music library folder. Never exposed. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $imagePath = null;

    /** File name of the image downloaded from TheAudioDB, in the media folder; used when there is no local image. Never exposed. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $downloadedImage = null;

    /** When TheAudioDB was last asked for an image of this artist, found or not. Never exposed. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $imageLookedUpAt = null;

    /**
     * Signed URL of the artist's thumbnail, usable without an Authorization header
     * (e.g. as the src of an <img>). Not stored: filled when the artist is serialized.
     */
    #[ApiProperty(description: 'Signed URL of a thumbnail of the artist (from the artist folder, or else TheAudioDB), or null when there is none; works without an Authorization header, e.g. as the src of an <img>')]
    #[Groups(['artist:read'])]
    private ?string $coverUrl = null;

    /**
     * Whether the user the request is authenticated as has this artist among their favourites. Not stored: filled
     * when the artist is serialized (App\Serializer\FavouriteNormalizer).
     */
    #[ApiProperty(description: 'Whether this artist is one of the favourites of the user making the request (PUT or DELETE /api/artists/{id}/favourite)')]
    #[Groups(['artist:read'])]
    private bool $favourite = false;

    public function __construct(
        #[ORM\Column(length: 255, unique: true)]
        #[Groups(['artist:read', 'album:read', 'track:read'])]
        private string $name,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getImagePath(): ?string
    {
        return $this->imagePath;
    }

    public function setImagePath(?string $imagePath): void
    {
        $this->imagePath = $imagePath;
    }

    public function getDownloadedImage(): ?string
    {
        return $this->downloadedImage;
    }

    public function setDownloadedImage(?string $downloadedImage): void
    {
        $this->downloadedImage = $downloadedImage;
    }

    public function getImageLookedUpAt(): ?\DateTimeImmutable
    {
        return $this->imageLookedUpAt;
    }

    public function setImageLookedUpAt(?\DateTimeImmutable $imageLookedUpAt): void
    {
        $this->imageLookedUpAt = $imageLookedUpAt;
    }

    /** Whether the artist has an image to serve, local or downloaded. */
    public function hasImage(): bool
    {
        return null !== $this->imagePath || null !== $this->downloadedImage;
    }

    public function getCoverUrl(): ?string
    {
        return $this->coverUrl;
    }

    public function setCoverUrl(?string $coverUrl): void
    {
        $this->coverUrl = $coverUrl;
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
