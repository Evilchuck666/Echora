<?php

namespace App\Favourites;

use App\Entity\Album;
use App\Entity\Artist;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\FavouriteRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Whether the user the request is authenticated as has an item among their favourites. Each kind is read once per
 * request, all at once (a page of 30 tracks makes one query, not 30), and forgotten afterwards: PHP workers serve
 * many requests and users (ResetInterface).
 */
final class CurrentFavourites implements ResetInterface
{
    /** @var array<string, array<string, true>> Ids by kind, for the current user */
    private array $ids = [];

    public function __construct(
        private readonly Security $security,
        private readonly FavouriteRepository $favourites,
    ) {
    }

    public function contains(Track|Album|Artist $item): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $kind = FavouriteKind::of($item);
        $this->ids[$kind->value] ??= array_fill_keys($this->favourites->findIds($user, $kind), true);

        return isset($this->ids[$kind->value][$item->getId()->toRfc4122()]);
    }

    /** After a favourite was added or removed in this request. */
    public function forget(): void
    {
        $this->ids = [];
    }

    public function reset(): void
    {
        $this->ids = [];
    }
}
