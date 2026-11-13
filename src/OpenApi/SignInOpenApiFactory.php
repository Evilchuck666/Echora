<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * Completes what the JWT bundle documents for POST /api/auth: the identifier can be the email, wrong credentials are
 * a 401, and too many failed attempts a 429 (login_throttling, docs/decisions/0014).
 */
// After the JWT bundle's own decorator (priority 0), which adds /api/auth.
#[AsDecorator(decorates: 'api_platform.openapi.factory', priority: -50)]
final readonly class SignInOpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        #[AutowireDecorated]
        private OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $path = $openApi->getPaths()->getPath('/api/auth');
        $operation = $path?->getPost();
        if (null === $path || null === $operation) {
            return $openApi;
        }

        $message = new \ArrayObject(['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => ['code' => ['type' => 'integer'], 'message' => ['type' => 'string']],
            'required' => ['code', 'message'],
        ]]]);
        $operation = $operation
            ->withSummary('Signs in: returns a JWT and a refresh token')
            ->withDescription('`username` is the username or the email of the account. '
                .'After 5 failed attempts for an account from one address (or 25 from one address, whatever the accounts) in 15 minutes, '
                .'every attempt is refused for a while, even with the right password: 429, with a Retry-After header (seconds). '
                .'A successful sign-in clears the count of that account.');
        $operation->addResponse(new Response('Wrong username, email or password', $message), '401');
        $operation->addResponse(new Response('Too many failed attempts: wait for the seconds of the Retry-After header', $message, headers: new \ArrayObject([
            'Retry-After' => ['description' => 'Seconds to wait', 'schema' => ['type' => 'integer']],
        ])), '429');
        $openApi->getPaths()->addPath('/api/auth', $path->withPost($operation));

        return $openApi;
    }
}
