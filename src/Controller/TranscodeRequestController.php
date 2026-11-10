<?php

namespace App\Controller;

use App\Dto\TranscodeRequest;
use App\Entity\User;
use App\Library\TranscodeQueue;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

/**
 * POST /api/transcodes: has the Opus versions of the tracks a client is about to play made in the background, so they
 * are ready when it asks for them. Answers 202 at once, without waiting for any conversion.
 * Documented by App\OpenApi\TranscodesOpenApiFactory.
 */
final class TranscodeRequestController extends AbstractController
{
    public function __construct(
        private readonly TranscodeQueue $queue,
    ) {
    }

    #[Route('/api/transcodes', name: 'transcodes', methods: ['POST'])]
    public function __invoke(#[MapRequestPayload] TranscodeRequest $request): JsonResponse
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->json($this->queue->request($user, $request->trackIds()), Response::HTTP_ACCEPTED);
    }
}
