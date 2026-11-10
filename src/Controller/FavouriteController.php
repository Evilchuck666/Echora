<?php

namespace App\Controller;

use App\Entity\User;
use App\Favourites\CurrentFavourites;
use App\Favourites\FavouriteKind;
use App\Repository\FavouriteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * PUT adds a track, album or artist to the favourites of the user making the request, DELETE removes it. Both are
 * idempotent and answer 204 whether or not it was a favourite before; 404 if the item doesn't exist.
 * Documented by App\OpenApi\FavouritesOpenApiFactory.
 */
final class FavouriteController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FavouriteRepository $favourites,
        private readonly CurrentFavourites $currentFavourites,
    ) {
    }

    #[Route('/api/{kind}/{id}/favourite', name: 'favourite', requirements: ['kind' => 'tracks|albums|artists', 'id' => Requirement::UUID], methods: ['PUT', 'DELETE'])]
    public function __invoke(Request $request, string $kind, string $id): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $class = FavouriteKind::fromPlural($kind)->entityClass();
        $item = $this->entityManager->find($class, Uuid::fromString($id))
            ?? throw $this->createNotFoundException(\sprintf('No %s with this id.', FavouriteKind::fromPlural($kind)->value));

        if ($request->isMethod('PUT')) {
            $this->favourites->add($user, $item);
        } else {
            $this->favourites->remove($user, $item);
        }
        $this->currentFavourites->forget();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
