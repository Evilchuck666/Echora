<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\ExactFilter;
use ApiPlatform\Doctrine\Orm\Filter\IriFilter;
use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
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
}
