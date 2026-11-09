<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents the streaming endpoint (a plain Symfony controller, see TrackStreamController)
 * in the OpenAPI specification, so generated clients know about it.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class TrackStreamOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $audio = new \ArrayObject([
            'audio/flac' => ['schema' => ['type' => 'string', 'format' => 'binary']],
            'audio/mpeg' => ['schema' => ['type' => 'string', 'format' => 'binary']],
        ]);

        $openApi->getPaths()->addPath('/api/tracks/{id}/stream', new PathItem(get: new Operation(
            operationId: 'stream_track',
            tags: ['Track'],
            responses: [
                '200' => new Response('The whole audio file', $audio),
                '206' => new Response('The byte range asked for in the Range header', $audio),
                '404' => new Response('The track does not exist, or its file is missing from the library'),
                '416' => new Response('The byte range asked for is outside the file'),
            ],
            summary: 'Streams the audio of a track',
            description: 'Returns the audio file as is (FLAC or MP3). Supports HTTP Range requests, so players can seek and start playing before the whole file has been downloaded.',
            parameters: [
                new Parameter('id', 'path', 'Track identifier', true, schema: ['type' => 'string', 'format' => 'uuid']),
                new Parameter('Range', 'header', 'Byte range to return, e.g. "bytes=0-1023"', schema: ['type' => 'string']),
            ],
        )));

        return $openApi;
    }
}
