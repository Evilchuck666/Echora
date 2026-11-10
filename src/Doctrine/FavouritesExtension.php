<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Favourite;
use App\Entity\User;
use App\Favourites\FavouriteKind;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * The /api/favourites/{tracks,albums,artists} collections: only the favourites of the user making the request,
 * newest first. API Platform's default order (and App\Doctrine\TrackOrderExtension) step aside, as an order is set.
 */
final readonly class FavouritesExtension implements QueryCollectionExtensionInterface
{
    /** Prefix of the names of those operations, followed by the plural of the kind ("favourites_tracks"). */
    public const string OPERATION_PREFIX = 'favourites_';

    public function __construct(private Security $security)
    {
    }

    public static function isFavouritesOperation(?Operation $operation): bool
    {
        return str_starts_with($operation?->getName() ?? '', self::OPERATION_PREFIX);
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (!self::isFavouritesOperation($operation)) {
            return;
        }
        $kind = FavouriteKind::fromPlural(substr((string) $operation?->getName(), \strlen(self::OPERATION_PREFIX)));
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $item = $queryBuilder->getRootAliases()[0];
        $favourite = $queryNameGenerator->generateJoinAlias('favourite');
        $parameter = $queryNameGenerator->generateParameterName('favourite_user');
        $queryBuilder
            ->innerJoin(Favourite::class, $favourite, Join::WITH, "$favourite.{$kind->value} = $item AND $favourite.user = :$parameter")
            ->setParameter($parameter, $user->getId(), 'uuid')
            ->addOrderBy("$favourite.createdAt", 'DESC')
            ->addOrderBy("$favourite.id", 'DESC');
    }
}
