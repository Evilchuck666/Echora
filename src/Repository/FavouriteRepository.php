<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Favourite;
use App\Entity\Track;
use App\Entity\User;
use App\Favourites\FavouriteKind;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Favourite>
 */
class FavouriteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Favourite::class);
    }

    /** Adds the item to the user's favourites; nothing happens if it is there already, even with two requests at once. */
    public function add(User $user, Track|Album|Artist $item): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            \sprintf('INSERT INTO favourite (id, user_id, %s_id, created_at) VALUES (?, ?, ?, ?) ON CONFLICT DO NOTHING', FavouriteKind::of($item)->value),
            [Uuid::v7()->toRfc4122(), $user->getId()->toRfc4122(), $item->getId()->toRfc4122(), new \DateTimeImmutable()],
            [3 => 'datetime_immutable'],
        );
    }

    public function remove(User $user, Track|Album|Artist $item): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            \sprintf('DELETE FROM favourite WHERE user_id = ? AND %s_id = ?', FavouriteKind::of($item)->value),
            [$user->getId()->toRfc4122(), $item->getId()->toRfc4122()],
        );
    }

    /**
     * The ids (RFC 4122) of the user's favourites of a kind.
     *
     * @return list<string>
     */
    public function findIds(User $user, FavouriteKind $kind): array
    {
        /** @var list<string> $ids */
        $ids = $this->createQueryBuilder('f')
            ->select(\sprintf('IDENTITY(f.%s)', $kind->value))
            ->where('f.user = :user')
            ->andWhere(\sprintf('f.%s IS NOT NULL', $kind->value))
            ->setParameter('user', $user->getId(), 'uuid')
            ->getQuery()
            ->getSingleColumnResult();

        return $ids;
    }
}
