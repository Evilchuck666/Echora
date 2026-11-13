<?php

namespace App\Entity;

use App\Repository\PlayRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One listen of a track by a user (POST /api/tracks/{id}/plays), for their history: recently played and most played
 * tracks (App\ApiResource\PlayedTrack). Deleted with the user and with the track. Not an API resource; written with
 * SQL (PlayRepository::record()).
 */
#[ORM\Entity(repositoryClass: PlayRepository::class)]
#[ORM\Index(name: 'play_user_played', columns: ['user_id', 'played_at'])]
#[ORM\Index(name: 'play_user_track', columns: ['user_id', 'track_id'])]
class Play
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Track $track,
        #[ORM\Column]
        private \DateTimeImmutable $playedAt,
    ) {
        $this->id = Uuid::v7();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getPlayedAt(): \DateTimeImmutable
    {
        return $this->playedAt;
    }
}
