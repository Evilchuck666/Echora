<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryBuilderHelper;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Track;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;

/**
 * Sorts GET /api/tracks in the order of the library as it is browsed: album artists by name, their albums by year
 * and title (as GET /api/albums), then disc and track number. The same for a whole library, an artist's albums or
 * one album. API Platform's own `order` can't reach the album's artist: it follows one relation, not two.
 */
final class TrackOrderExtension implements QueryCollectionExtensionInterface
{
    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        if (Track::class !== $resourceClass) {
            return;
        }
        $track = $queryBuilder->getRootAliases()[0];
        $album = QueryBuilderHelper::addJoinOnce($queryBuilder, $queryNameGenerator, $track, 'album', Join::INNER_JOIN);
        $artist = QueryBuilderHelper::addJoinOnce($queryBuilder, $queryNameGenerator, $album, 'artist', Join::INNER_JOIN);

        $queryBuilder
            ->addOrderBy("$artist.name", 'ASC')
            ->addOrderBy("$album.year", 'ASC')
            ->addOrderBy("$album.title", 'ASC')
            ->addOrderBy("$track.discNumber", 'ASC')
            ->addOrderBy("$track.trackNumber", 'ASC')
            ->addOrderBy("$track.title", 'ASC')
            // Ties (e.g. two untagged files): always the same order, or a page boundary could repeat or skip a track.
            ->addOrderBy("$track.id", 'ASC');
    }
}
