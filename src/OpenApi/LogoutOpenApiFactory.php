<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents POST /api/logout in the OpenAPI specification. The endpoint is the logout listener of
 * the "api" firewall (security.yaml), so there is no controller or resource for API Platform to find.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class LogoutOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $message = new \ArrayObject(['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => ['code' => ['type' => 'integer'], 'message' => ['type' => 'string']],
            'required' => ['code', 'message'],
        ]]]);

        $openApi->getPaths()->addPath('/api/logout', new PathItem(post: new Operation(
            operationId: 'logout',
            tags: ['Login Check'],
            responses: [
                '200' => new Response('The refresh token no longer works (it was invalidated now, or already was)', $message),
                '400' => new Response('The body has no refresh_token', $message),
            ],
            summary: 'Signs out: invalidates a refresh token.',
            description: 'Deletes the refresh token, so the session it belongs to cannot get new JWTs. '
                .'Needs no Authorization header, so it also works once the JWT has expired; a JWT already issued stays valid until it expires (one hour at most).',
            requestBody: new RequestBody(
                description: 'The refresh token of the session to end',
                content: new \ArrayObject(['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['refresh_token' => ['type' => 'string']],
                    'required' => ['refresh_token'],
                ]]]),
                required: true,
            ),
            security: [],
        )));

        return $openApi;
    }
}
