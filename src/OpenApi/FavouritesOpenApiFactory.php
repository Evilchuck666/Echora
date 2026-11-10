<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Favourites\FavouriteKind;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents PUT and DELETE /api/{tracks,albums,artists}/{id}/favourite (App\Controller\FavouriteController, a plain
 * Symfony controller) in the OpenAPI specification, so generated clients know about them.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class FavouritesOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        foreach (FavouriteKind::cases() as $kind) {
            $name = $kind->value;
            $tag = ucfirst($name);
            $id = new Parameter('id', 'path', ucfirst($name).' identifier', true, schema: ['type' => 'string', 'format' => 'uuid']);
            $responses = static fn (string $done): array => [
                '204' => new Response($done),
                '401' => new Response('No valid JWT'),
                '404' => new Response("The $name does not exist"),
            ];

            $openApi->getPaths()->addPath("/api/{$kind->plural()}/{id}/favourite", new PathItem(
                put: new Operation(
                    operationId: "add_favourite_$name",
                    tags: [$tag],
                    responses: $responses("The $name is one of the user's favourites (now, or already was)"),
                    summary: "Adds the $name to the favourites of the user making the request",
                    description: "Idempotent: adding a favourite twice is not an error. The user's favourite {$kind->plural()} are listed by GET /api/favourites/{$kind->plural()}.",
                    parameters: [$id],
                ),
                delete: new Operation(
                    operationId: "remove_favourite_$name",
                    tags: [$tag],
                    responses: $responses("The $name is not one of the user's favourites (now, or already wasn't)"),
                    summary: "Removes the $name from the favourites of the user making the request",
                    description: 'Idempotent: removing a favourite that is not there is not an error.',
                    parameters: [$id],
                ),
            ));
        }

        return $openApi;
    }
}
