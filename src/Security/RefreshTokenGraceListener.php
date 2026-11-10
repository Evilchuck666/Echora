<?php

namespace App\Security;

use Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * A grace period for refresh tokens whose new pair never reached the client.
 *
 * Refresh tokens are single use, and presenting a spent one revokes the whole session (reuse detection). But a
 * refresh can succeed on the server and its response be lost: the page was reloaded or closed meanwhile, or the
 * network dropped. The client still holds the spent token, presents it again, and would be signed out.
 *
 * So for REFRESH_TOKEN_GRACE_SECONDS after a refresh, a spent token is taken as the token that replaced it, as long
 * as that one has never been used: the request goes on as if the client had presented it (RefreshTokenSuccessorExtractor),
 * and gets a new pair. Theft is still detected: whoever holds the successor and uses it later presents a spent token,
 * which revokes the session. A spent token whose successor was already used, or presented after the grace period,
 * is reuse as before.
 */
final readonly class RefreshTokenGraceListener
{
    /** The spent token the client presented, when its successor is refreshed instead. */
    private const string PRESENTED = '_refresh_token_presented';

    public function __construct(
        private RefreshTokenSuccessors $successors,
        private RefreshTokenManagerInterface $refreshTokens,
        #[Autowire(service: 'gesdinet_jwt_refresh_token.request.extractor.chain')]
        private ExtractorInterface $extractor,
        #[Autowire(param: 'gesdinet_jwt_refresh_token.token_parameter_name')]
        private string $tokenParameterName,
        private RequestStack $requestStack,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * After the router (priority 32), so the route is known, and before RefreshTokenLockListener (16), so that the
     * lock is taken on the token that will actually be spent.
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 17)]
    public function useTheSuccessorOfASpentToken(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'api_refresh_token' !== $request->attributes->get('_route')) {
            return;
        }

        $presented = $this->extractor->getRefreshToken($request, $this->tokenParameterName);
        if (null === $presented || '' === $presented || null !== $this->refreshTokens->get($presented)) {
            return;
        }

        // Only the direct successor, and only while unused: one that was used means the client did get it, and
        // presenting the token before it is reuse. (After several lost responses in a row, the presented token
        // points to the latest pair: see rememberTheSuccessor().)
        $successor = $this->successors->recall($presented);
        $stored = null === $successor ? null : $this->refreshTokens->get($successor);
        if (null === $successor || null === $stored || !$stored->isValid()) {
            return;
        }

        $request->attributes->set(RefreshTokenSuccessorExtractor::ATTRIBUTE, $successor);
        $request->attributes->set(self::PRESENTED, $presented);
        $this->logger?->info('A spent refresh token was presented within the grace period: refreshing its unused successor instead.');
    }

    /**
     * After the bundle issued the new refresh token (AttachRefreshTokenOnSuccessListener, priority 0).
     */
    #[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, priority: -10)]
    public function rememberTheSuccessor(AuthenticationSuccessEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request || 'api_refresh_token' !== $request->attributes->get('_route')) {
            return;
        }

        // The token the client holds. When the grace period applied, that's the spent token it presented, not the
        // successor refreshed instead: the client never had that one, and presenting it later is reuse.
        $presented = $request->attributes->get(self::PRESENTED);
        $replaced = \is_string($presented) ? $presented : $this->extractor->getRefreshToken($request, $this->tokenParameterName);
        $issued = $event->getData()[$this->tokenParameterName] ?? null;
        if (null !== $replaced && \is_string($issued) && '' !== $issued) {
            $this->successors->remember($replaced, $issued);
        }
    }
}
