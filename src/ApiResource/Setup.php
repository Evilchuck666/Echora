<?php

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Response;
use App\Dto\SetupInput;
use App\State\SetupProcessor;
use App\State\SetupProvider;

/**
 * Setting up a new server: until it has an account, whoever comes first creates the administrator (like Immich).
 * Public routes (security.yaml).
 */
#[ApiResource(
    operations: [
        new Get(
            uriTemplate: '/setup',
            uriVariables: [],
            openapi: new Operation(
                summary: 'Whether the server still needs its first account',
                description: 'Public. While `required` is true, clients show a screen to create the administrator (POST /api/setup) instead of signing in.',
            ),
            provider: SetupProvider::class,
        ),
        new Post(
            uriTemplate: '/setup',
            status: 201,
            openapi: new Operation(
                responses: [
                    '201' => new Response('The administrator was created and is signed in: a JWT and a refresh token, as by POST /api/auth'),
                    '409' => new Response('The server already has an account: sign in instead'),
                    '422' => new Response('An invalid email, name or password'),
                ],
                summary: 'Creates the first account of the server, which becomes its administrator',
                description: 'Public, and only while the server has no account. The username is made from the email (the part before the @); it can be changed afterwards.',
            ),
            input: SetupInput::class,
            processor: SetupProcessor::class,
        ),
    ],
)]
final readonly class Setup
{
    public function __construct(
        #[ApiProperty(description: 'True while the server has no account')]
        public bool $required,
    ) {
    }
}
