<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\PartialSearchFilter;
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
}
