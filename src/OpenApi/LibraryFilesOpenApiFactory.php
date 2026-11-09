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
 * Documents the endpoints that serve library files (plain Symfony controllers:
 * TrackStreamController, AlbumCoverController, ArtistCoverController) in the OpenAPI specification, so
 * generated clients know about them.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class LibraryFilesOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $paths = $openApi->getPaths();

        $paths->addPath('/api/tracks/{id}/stream', new PathItem(get: new Operation(
            operationId: 'stream_track',
            tags: ['Track'],
            responses: [
                '200' => new Response('The whole audio file', $this->binary('audio/flac', 'audio/mpeg')),
                '206' => new Response('The byte range asked for in the Range header', $this->binary('audio/flac', 'audio/mpeg')),
                '401' => new Response('Neither a valid JWT nor a valid, unexpired signature'),
                '404' => new Response('The track does not exist, or its file is missing from the library'),
                '416' => new Response('The byte range asked for is outside the file'),
            ],
            summary: 'Streams the audio of a track',
            description: 'Returns the audio file as is (FLAC or MP3). Supports HTTP Range requests, so players can seek and start playing before the whole file has been downloaded. '
                .'Authenticate with a JWT, or call the streamUrl of the track as is: it carries a signature (_hash, _expiration) and needs no Authorization header, so it can be the src of an <audio> element.',
            parameters: [
                $this->id('Track identifier'),
                new Parameter('Range', 'header', 'Byte range to return, e.g. "bytes=0-1023"', schema: ['type' => 'string']),
                ...$this->signature('streamUrl'),
            ],
            security: [['JWT' => []], []],
        )));

        $paths->addPath('/api/albums/{id}/cover', new PathItem(get: new Operation(
            operationId: 'get_album_cover',
            tags: ['Album'],
            responses: [
                '200' => new Response('The cover image', $this->binary('image/jpeg', 'image/png', 'image/webp')),
                '401' => new Response('Neither a valid JWT nor a valid, unexpired signature'),
                '404' => new Response('The album does not exist or has no cover, or its cover is missing'),
            ],
            summary: 'Returns the cover image of an album',
            description: 'Returns the cover found in the album folder (folder, cover, front or album, in JPEG, PNG or WebP), or else downloaded from TheAudioDB (theaudiodb.com). '
                .'Authenticate with a JWT, or call the coverUrl of the album as is: it needs no Authorization header, so it can be the src of an <img>. It stays the same for a day, so browsers can cache it.',
            parameters: [$this->id('Album identifier'), ...$this->signature('coverUrl')],
            security: [['JWT' => []], []],
        )));

        $paths->addPath('/api/artists/{id}/cover', new PathItem(get: new Operation(
            operationId: 'get_artist_cover',
            tags: ['Artist'],
            responses: [
                '200' => new Response('A thumbnail of the artist (JPEG, square, at most 300 pixels a side)', $this->binary('image/jpeg')),
                '401' => new Response('Neither a valid JWT nor a valid, unexpired signature'),
                '404' => new Response('The artist does not exist or has no image, or its image is missing or unreadable'),
            ],
            summary: 'Returns a thumbnail of an artist',
            description: 'Returns a small square JPEG made from the image in the artist folder (artist or folder, in JPEG, PNG or WebP), or else from TheAudioDB (theaudiodb.com). '
                .'Authenticate with a JWT, or call the coverUrl of the artist as is: it needs no Authorization header, so it can be the src of an <img>. It stays the same for a day, so browsers can cache it.',
            parameters: [$this->id('Artist identifier'), ...$this->signature('coverUrl')],
            security: [['JWT' => []], []],
        )));

        return $openApi;
    }

    /**
     * @return \ArrayObject<string, array{schema: array{type: string, format: string}}>
     */
    private function binary(string ...$mimeTypes): \ArrayObject
    {
        return new \ArrayObject(array_fill_keys($mimeTypes, ['schema' => ['type' => 'string', 'format' => 'binary']]));
    }

    private function id(string $description): Parameter
    {
        return new Parameter('id', 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);
    }

    /**
     * @return list<Parameter>
     */
    private function signature(string $field): array
    {
        return [
            new Parameter('_expiration', 'query', \sprintf('Expiration of a signed %s (Unix timestamp); part of the URL, not to be built by hand', $field), schema: ['type' => 'integer']),
            new Parameter('_hash', 'query', \sprintf('Signature of a signed %s; part of the URL, not to be built by hand', $field), schema: ['type' => 'string']),
        ];
    }
}
