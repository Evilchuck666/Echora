<?php

namespace App\Repository;

use App\Entity\PlaylistItem;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlaylistItem>
 */
class PlaylistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlaylistItem::class);
    }

    /**
     * The item, if it exists and its playlist belongs to the user.
     */
    public function findOwned(User $user, string $id): ?PlaylistItem
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $item = $this->find(Uuid::fromString($id));

        return null !== $item && $item->getPlaylist()->isOwnedBy($user) ? $item : null;
    }
}
