<?php

namespace App\Entity;

use App\Repository\FavouriteRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * A track, album or artist that a user marked as a favourite: exactly one of the three (a CHECK constraint in the
 * database says so too). Deleted with its user or its item, e.g. when a scan removes a track. Not an API resource:
 * see App\Controller\FavouriteController and the /api/favourites/* collections of Track, Album and Artist. Added and
 * removed with SQL (FavouriteRepository), so that two requests at once can't both insert it.
 */
#[ORM\Entity(repositoryClass: FavouriteRepository::class)]
#[ORM\UniqueConstraint(name: 'favourite_user_track', columns: ['user_id', 'track_id'])]
#[ORM\UniqueConstraint(name: 'favourite_user_album', columns: ['user_id', 'album_id'])]
#[ORM\UniqueConstraint(name: 'favourite_user_artist', columns: ['user_id', 'artist_id'])]
#[ORM\Index(name: 'favourite_user_created', columns: ['user_id', 'created_at'])]
class Favourite
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Track $track = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Album $album = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Artist $artist = null;

    public function __construct(User $user, Track|Album|Artist $item)
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->user = $user;
        match (true) {
            $item instanceof Track => $this->track = $item,
            $item instanceof Album => $this->album = $item,
            $item instanceof Artist => $this->artist = $item,
        };
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getItem(): Track|Album|Artist
    {
        return $this->track ?? $this->album ?? $this->artist ?? throw new \LogicException('A favourite always has an item.');
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
