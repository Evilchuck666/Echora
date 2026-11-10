<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Playlist;
use App\Entity\PlaylistItem;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Playlists and their items only exist for their owner: anyone else's are left out of lists and not found (404, not
 * 403, so their existence doesn't leak) for GET, PATCH and DELETE.
 */
final readonly class OwnedByCurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private Security $security)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->restrict($queryBuilder, $queryNameGenerator, $resourceClass);
    }

    private function restrict(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass): void
    {
        if (Playlist::class !== $resourceClass && PlaylistItem::class !== $resourceClass) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        $root = $queryBuilder->getRootAliases()[0];
        if (PlaylistItem::class === $resourceClass) {
            $playlist = $queryNameGenerator->generateJoinAlias('playlist');
            $queryBuilder->innerJoin("$root.playlist", $playlist);
            $root = $playlist;
        }
        $parameter = $queryNameGenerator->generateParameterName('owner');
        $queryBuilder->andWhere("$root.owner = :$parameter")->setParameter($parameter, $user->getId(), 'uuid');
    }
}
