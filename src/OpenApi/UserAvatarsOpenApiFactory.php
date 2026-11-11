<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Controller\UserAvatarController;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Documents the profile picture endpoints (App\Controller\UserAvatarController, a plain Symfony controller) in the
 * OpenAPI specification, so generated clients know about them.
 */
#[AsDecorator(decorates: 'api_platform.openapi.factory')]
final readonly class UserAvatarsOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $maxMegabytes = UserAvatarController::MAX_BYTES / 1024 / 1024;
        $upload = new RequestBody(
            "The picture: JPEG, PNG or WebP, up to $maxMegabytes MB, in a multipart/form-data field named \"file\". It is cropped to a centred square of up to 300×300 and encoded again as JPEG (without its metadata).",
            new \ArrayObject(['multipart/form-data' => ['schema' => [
                'type' => 'object',
                'required' => ['file'],
                'properties' => ['file' => ['type' => 'string', 'format' => 'binary']],
            ]]]),
            true,
        );
        $account = new \ArrayObject(['application/json' => ['schema' => ['$ref' => '#/components/schemas/User-user.read']]]);
        $id = new Parameter('id', 'path', 'Account identifier', true, schema: ['type' => 'string', 'format' => 'uuid']);

        $openApi->getPaths()->addPath('/api/me/avatar', new PathItem(
            post: new Operation(
                operationId: 'upload_my_avatar',
                tags: ['User'],
                responses: [
                    '200' => new Response('The account, with its new avatarUrl', $account),
                    '401' => new Response('No valid JWT'),
                    '422' => new Response("No file, not a JPEG, PNG or WebP picture, or more than $maxMegabytes MB"),
                ],
                summary: 'Sets the profile picture of the account the request is authenticated as',
                requestBody: $upload,
            ),
            delete: new Operation(
                operationId: 'delete_my_avatar',
                tags: ['User'],
                responses: ['204' => new Response('The account has no picture (now, or already had none)'), '401' => new Response('No valid JWT')],
                summary: 'Removes the profile picture of the account the request is authenticated as',
            ),
        ));

        $openApi->getPaths()->addPath('/api/users/{id}/avatar', new PathItem(
            get: new Operation(
                operationId: 'get_user_avatar',
                tags: ['User'],
                responses: [
                    '200' => new Response('The picture (JPEG, square, at most 300 pixels a side)', new \ArrayObject(['image/jpeg' => ['schema' => ['type' => 'string', 'format' => 'binary']]])),
                    '401' => new Response('Neither a valid JWT nor a valid, unexpired signature'),
                    '404' => new Response('The account does not exist or has no picture'),
                ],
                summary: 'The profile picture of an account',
                description: 'Use the avatarUrl of the account: it carries a signature (_hash, _expiration) and needs no Authorization header, so it can be the src of an <img>. It changes with the picture.',
                parameters: [$id],
            ),
            post: new Operation(
                operationId: 'upload_user_avatar',
                tags: ['User'],
                responses: [
                    '200' => new Response('The account, with its new avatarUrl', $account),
                    '403' => new Response('Not an administrator'),
                    '404' => new Response('The account does not exist'),
                    '422' => new Response("No file, not a JPEG, PNG or WebP picture, or more than $maxMegabytes MB"),
                ],
                summary: 'Sets the profile picture of an account (administrators only)',
                parameters: [$id],
                requestBody: $upload,
            ),
            delete: new Operation(
                operationId: 'delete_user_avatar',
                tags: ['User'],
                responses: [
                    '204' => new Response('The account has no picture'),
                    '403' => new Response('Not an administrator'),
                    '404' => new Response('The account does not exist'),
                ],
                summary: 'Removes the profile picture of an account (administrators only)',
                parameters: [$id],
            ),
        ));

        return $openApi;
    }
}
