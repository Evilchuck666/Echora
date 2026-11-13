<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Repository\PlayRepository;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents POST /api/tracks/{id}/plays and DELETE /api/me/history (App\Controller\PlayController, a plain Symfony
 * controller) in the OpenAPI specification, so generated clients know about them.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class HistoryOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $seconds = PlayRepository::SAME_PLAY_SECONDS;

        $openApi->getPaths()->addPath('/api/tracks/{id}/plays', new PathItem(post: new Operation(
            operationId: 'play_track',
            tags: ['Track'],
            responses: [
                '204' => new Response('Recorded (or the same play was already)'),
                '401' => new Response('No valid JWT'),
                '404' => new Response('The track does not exist'),
                '422' => new Response('A playedAt in the future, or more than a year ago'),
            ],
            summary: 'Records a play of the track in the listening history of the user making the request',
            description: 'Send it once the track counts as listened to: when it ends, or once half of it (or 4 minutes) has played, whichever comes first. '
                ."A play of the same track within $seconds seconds of another one counts once, so retrying is safe. "
                .'Without a body, the play is now; a client that played offline sends `playedAt` (ISO 8601) when it reconnects. '
                .'The history is read through GET /api/me/recent-tracks and /api/me/top-tracks.',
            parameters: [new Parameter('id', 'path', 'Track identifier', true, schema: ['type' => 'string', 'format' => 'uuid'])],
            requestBody: new RequestBody(
                'Optional: when the track was played',
                new \ArrayObject(['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['playedAt' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When it was played, if not now: within the last year']],
                ]]]),
                false,
            ),
        )));

        $openApi->getPaths()->addPath('/api/me/history', new PathItem(delete: new Operation(
            operationId: 'clear_history',
            tags: ['User'],
            responses: ['204' => new Response('The listening history is empty'), '401' => new Response('No valid JWT')],
            summary: 'Forgets every play of the user making the request',
        )));

        return $openApi;
    }
}
