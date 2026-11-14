<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Download\DownloadFormat;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents the download manifests and files (App\Controller\DownloadController, a plain Symfony controller) in the
 * OpenAPI specification, so generated clients know about them.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class DownloadsOpenApiFactory implements OpenApiFactoryInterface
{
    private const array KINDS = ['tracks' => 'track', 'albums' => 'album', 'artists' => 'artist', 'playlists' => 'playlist'];

    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $id = static fn (string $what): Parameter => new Parameter('id', 'path', ucfirst($what).' identifier', true, schema: ['type' => 'string', 'format' => 'uuid']);
        $manifest = new \ArrayObject(['application/json' => ['schema' => self::manifestSchema()]]);

        foreach (self::KINDS as $plural => $kind) {
            $what = match ($kind) {
                'artist' => 'the tracks of the artist: their own albums, and their tracks on other artists\' albums',
                'playlist' => 'the tracks of the playlist, once each, and `items`, the track of each item in order (a track may come several times), to rebuild it. Someone else\'s playlist is a 404',
                'album' => 'the tracks of the album',
                default => 'the track',
            };
            $openApi->getPaths()->addPath("/api/$plural/{id}/download", new PathItem(get: new Operation(
                operationId: "download_$kind",
                tags: [ucfirst($kind)],
                responses: [
                    '200' => new Response('What to download', $manifest),
                    '401' => new Response('No valid JWT'),
                    '404' => new Response("The $kind does not exist"),
                    '422' => new Response('An unknown format'),
                ],
                summary: "What to download to play the $kind offline",
                description: "Lists $what, in order, each once, with a signed URL to its file (valid a day: `expiresAt`), its format, its size and where to save it (`savePath`), and the covers of their albums. "
                    .'Download the files one by one: they are served with byte ranges, so a download cut short resumes. '
                    .'With `format=opus`, FLAC tracks come converted to Opus (about a quarter of the size; the first download of a track waits for its conversion, and its size is null until then) and MP3 tracks as they are: `format` says what each file is.',
                parameters: [
                    $id($kind),
                    new Parameter('format', 'query', 'original (the files as they are, the default) or opus, e.g. while the client saves data', false, schema: ['type' => 'string', 'enum' => array_map(static fn (DownloadFormat $format): string => $format->value, DownloadFormat::cases()), 'default' => 'original']),
                ],
            )));
        }

        foreach (['/api/tracks/{id}/file' => ['original', 'The audio file as it is (FLAC or MP3)', 'audio/flac'], '/api/tracks/{id}/file/opus' => ['opus', 'The audio of a FLAC track converted to Opus', 'audio/ogg']] as $path => [$suffix, $summary, $type]) {
            $openApi->getPaths()->addPath($path, new PathItem(get: new Operation(
                operationId: "download_track_file_$suffix",
                tags: ['Track'],
                responses: [
                    '200' => new Response('The file, as an attachment with a readable name (Content-Disposition)', new \ArrayObject([$type => ['schema' => ['type' => 'string', 'format' => 'binary']]])),
                    '206' => new Response('The byte range asked for in the Range header'),
                    '401' => new Response('Neither a valid JWT nor a valid, unexpired signature'),
                    '404' => new Response('opus' === $suffix ? 'The track does not exist, is not a FLAC file, or its file is missing' : 'The track does not exist, or its file is missing'),
                ],
                summary: "$summary, to download",
                description: 'Use the `url` given by a download manifest: it carries a signature (valid a day) and needs no Authorization header.',
                parameters: [$id('track'), new Parameter('Range', 'header', 'Byte range to return, e.g. "bytes=1048576-", to resume', schema: ['type' => 'string'])],
            )));
        }

        return $openApi;
    }

    /**
     * @return array<string, mixed>
     */
    private static function manifestSchema(): array
    {
        $nullableInt = ['type' => ['integer', 'null']];

        return [
            'type' => 'object',
            'required' => ['kind', 'id', 'name', 'format', 'expiresAt', 'trackCount', 'totalSize', 'totalSizeComplete', 'tracks', 'covers'],
            'properties' => [
                'kind' => ['type' => 'string', 'enum' => array_values(self::KINDS)],
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'name' => ['type' => 'string', 'description' => 'Title of the track or album, name of the artist or playlist'],
                'format' => ['type' => 'string', 'enum' => ['original', 'opus'], 'description' => 'The format asked for'],
                'expiresAt' => ['type' => 'string', 'format' => 'date-time', 'description' => 'When the URLs stop working: ask for the manifest again'],
                'trackCount' => ['type' => 'integer'],
                'totalSize' => ['type' => 'integer', 'description' => 'Bytes, of the files whose size is known'],
                'totalSizeComplete' => ['type' => 'boolean', 'description' => 'False when some Opus files are not converted yet, so their size is unknown'],
                'tracks' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string', 'format' => 'uuid'],
                        'title' => ['type' => 'string'],
                        'artist' => ['type' => 'string'],
                        'album' => ['type' => 'string'],
                        'discNumber' => $nullableInt,
                        'trackNumber' => $nullableInt,
                        'durationMs' => $nullableInt,
                        'format' => ['type' => 'string', 'enum' => ['flac', 'mp3', 'opus'], 'description' => 'What the file is'],
                        'mimeType' => ['type' => 'string'],
                        'size' => $nullableInt + ['description' => 'Bytes; null for an Opus file not converted yet'],
                        'savePath' => ['type' => 'string', 'example' => 'Test Artist/2020 - First Album/01 - Opening.flac', 'description' => 'Where to save it, relative, with names safe on every system'],
                        'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Signed URL of the file (GET /api/tracks/{id}/file or /file/opus)'],
                    ],
                ]],
                'covers' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'album' => ['type' => 'string', 'format' => 'uuid'],
                        'savePath' => ['type' => 'string', 'example' => 'Test Artist/2020 - First Album/cover.jpg'],
                        'url' => ['type' => 'string', 'format' => 'uri'],
                    ],
                ]],
                'items' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'Playlists only: the track of each item, in order'],
            ],
        ];
    }
}
