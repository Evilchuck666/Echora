<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Dto\TranscodeRequest;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents POST /api/transcodes (App\Controller\TranscodeRequestController, a plain Symfony controller) in the
 * OpenAPI specification, so generated clients know about it.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class TranscodesOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $ids = static fn (string $description): array => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'description' => $description];
        $max = TranscodeRequest::MAX_TRACKS;

        $openApi->getPaths()->addPath('/api/transcodes', new PathItem(
            post: new Operation(
                operationId: 'transcode_tracks',
                tags: ['Track'],
                responses: [
                    '202' => new Response(
                        'Accepted: the conversions run in the background, after the response. Each track that has an Opus version is in one of the lists; the others (MP3, unknown tracks) in none.',
                        new \ArrayObject(['application/json' => ['schema' => [
                            'type' => 'object',
                            'required' => ['queued', 'ready', 'converting'],
                            'properties' => [
                                'queued' => $ids('Tracks that will be converted, one after the other, in this order'),
                                'ready' => $ids('Tracks already converted: their opusStreamUrl answers at once'),
                                'converting' => $ids('Tracks being converted right now'),
                            ],
                        ]]]),
                    ),
                    '400' => new Response('The body is not JSON'),
                    '401' => new Response('No valid JWT'),
                    '422' => new Response("More than $max tracks, or something that is neither a track id nor its IRI"),
                ],
                summary: 'Converts the tracks a client is about to play to Opus, ahead of time',
                description: 'The first request for a track\'s `opusStreamUrl` waits for its conversion (a few seconds). '
                    ."Send the next tracks of the queue (at most $max, next one first) to have them converted in the background, so they are ready when the player asks for them. "
                    .'Answers at once, without waiting for any conversion. '
                    .'Each request replaces the previous one of the same user (from any device): the tracks of an older request that are not converted yet are dropped, so send the whole upcoming list every time the queue changes. An empty list only cancels the previous request. '
                    .'MP3 tracks (no `opusStreamUrl`), unknown tracks, tracks already converted and tracks being converted are left out. '
                    .'The server converts a limited number of tracks at once (TRANSCODE_WORKERS, 1 by default).',
                requestBody: new RequestBody(
                    'The tracks, next one first',
                    new \ArrayObject(['application/json' => ['schema' => [
                        'type' => 'object',
                        'required' => ['tracks'],
                        'properties' => [
                            'tracks' => [
                                'type' => 'array',
                                'maxItems' => $max,
                                'items' => ['type' => 'string'],
                                'description' => 'Track ids or IRIs (/api/tracks/{id}); duplicates are ignored',
                                'example' => ['/api/tracks/0199e1f0-6b1e-7d3c-9a4b-2f5e8c7d1a00', '0199e1f0-6b1e-7d3c-9a4b-2f5e8c7d1a01'],
                            ],
                        ],
                    ]]]),
                    true,
                ),
            ),
        ));

        return $openApi;
    }
}
