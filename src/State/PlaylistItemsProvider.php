<?php

namespace App\State;

use ApiPlatform\Doctrine\Orm\Paginator;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\Pagination;
use ApiPlatform\State\ProviderInterface;
use App\Entity\PlaylistItem;
use App\Entity\User;
use App\Repository\PlaylistItemRepository;
use App\Repository\PlaylistRepository;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /api/playlists/{id}/items: a page of the playlist's items in its order, each with its position (index from 0)
 * and its whole track (album and artist fetched in the same query). 404 when the playlist isn't the user's.
 *
 * @implements ProviderInterface<PlaylistItem>
 */
final readonly class PlaylistItemsProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private PlaylistRepository $playlists,
        private PlaylistItemRepository $items,
        private Pagination $pagination,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Paginator
    {
        $user = $this->security->getUser();
        \assert($user instanceof User);
        $playlist = $this->playlists->findOwned($user, UriVariable::string($uriVariables, 'id'))
            ?? throw new NotFoundHttpException('No playlist with this id.');

        $offset = $this->pagination->getOffset($operation, $context);
        $limit = $this->pagination->getLimit($operation, $context);
        $query = $this->items->createQueryBuilder('i')
            ->addSelect('t', 'al', 'ar')
            ->innerJoin('i.track', 't')
            ->innerJoin('t.album', 'al')
            ->innerJoin('t.artist', 'ar')
            ->where('i.playlist = :playlist')
            ->setParameter('playlist', $playlist->getId(), 'uuid')
            ->orderBy('i.sortKey', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery();

        // Only to-one joins: the plain paginator query is enough.
        $paginator = new Paginator(new DoctrinePaginator($query, fetchJoinCollection: false));
        $position = $offset;
        foreach ($paginator as $item) {
            \assert($item instanceof PlaylistItem);
            $item->setPosition($position++);
        }

        return $paginator;
    }
}
