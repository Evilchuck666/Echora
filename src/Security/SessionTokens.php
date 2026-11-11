<?php

namespace App\Security;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * A new session for an account, answered exactly like POST /api/auth: a JWT and a refresh token (the refresh token
 * bundle adds it when the JWT bundle reports a successful authentication).
 */
final readonly class SessionTokens
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_success')]
        private AuthenticationSuccessHandler $successHandler,
    ) {
    }

    public function respond(User $user, int $status = Response::HTTP_OK): Response
    {
        return $this->successHandler->handleAuthenticationSuccess($user)->setStatusCode($status);
    }
}
