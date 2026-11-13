<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;

/**
 * Failed sign-ins (POST /api/auth): 401 from the JWT bundle, as before, except when the attempts are throttled
 * (login_throttling in security.yaml): 429 Too Many Requests, with Retry-After, in the same JSON shape.
 */
final readonly class LoginFailureHandler implements AuthenticationFailureHandlerInterface
{
    public function __construct(
        #[Autowire(service: 'lexik_jwt_authentication.handler.authentication_failure')]
        private AuthenticationFailureHandlerInterface $decorated,
    ) {
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (!$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return $this->decorated->onAuthenticationFailure($request, $exception);
        }

        $minutes = $exception->getMessageData()['%minutes%'] ?? 1;
        $minutes = max(1, is_numeric($minutes) ? (int) $minutes : 1);

        return new JsonResponse(
            ['code' => Response::HTTP_TOO_MANY_REQUESTS, 'message' => \sprintf('Too many failed sign-in attempts: try again in %d minute%s.', $minutes, 1 === $minutes ? '' : 's')],
            Response::HTTP_TOO_MANY_REQUESTS,
            ['Retry-After' => (string) ($minutes * 60)],
        );
    }
}
