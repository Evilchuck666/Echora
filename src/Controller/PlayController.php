<?php

namespace App\Controller;

use App\Dto\PlayInput;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\PlayRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * The listening history of the user making the request (docs/decisions/0016): POST records a play of a track (204;
 * the same play sent again within 30 seconds counts once), DELETE /api/me/history forgets every play. Read through
 * /api/me/recent-tracks and /api/me/top-tracks (App\ApiResource\PlayedTrack). Documented by
 * App\OpenApi\HistoryOpenApiFactory.
 */
final class PlayController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PlayRepository $plays,
    ) {
    }

    #[Route('/api/tracks/{id}/plays', name: 'track_play', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function play(string $id, #[MapRequestPayload] ?PlayInput $input = null): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $track = $this->entityManager->find(Track::class, Uuid::fromString($id)) ?? throw $this->createNotFoundException('No track with this id.');

        $this->plays->record($user, $track, $input->playedAt ?? new \DateTimeImmutable());

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/me/history', name: 'my_history', methods: ['DELETE'])]
    public function clear(): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);
        $this->plays->clear($user);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
