<?php

namespace App\Security;

use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\ExtractorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Lets only one request at a time spend a given refresh token.
 *
 * The refresh authenticator looks the token up, and the success listener deletes it later in the
 * same request. Two requests presenting the same token at once could both find it and both get a
 * new pair, leaving two live chains that reuse detection never notices. Holding a lock on the token
 * around the firewall makes the second request wait until the first one has spent it: it is then
 * refused and its chain revoked, as when the two requests arrive one after the other.
 */
final class RefreshTokenLockListener
{
    private const string LOCK_ATTRIBUTE = '_refresh_token_lock';

    public function __construct(
        private readonly LockFactory $lockFactory,
        #[Autowire(service: 'gesdinet_jwt_refresh_token.request.extractor.chain')]
        private readonly ExtractorInterface $extractor,
        #[Autowire(param: 'gesdinet_jwt_refresh_token.token_parameter_name')]
        private readonly string $tokenParameterName,
    ) {
    }

    /**
     * After the router (priority 32), so the route is known, and before the firewall (priority 8).
     */
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 16)]
    public function acquire(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || 'api_refresh_token' !== $request->attributes->get('_route')) {
            return;
        }

        $token = $this->extractor->getRefreshToken($request, $this->tokenParameterName);
        if (null === $token || '' === $token) {
            return;
        }

        // A hash keeps the token itself out of the lock store.
        $lock = $this->lockFactory->createLock('refresh_token.'.hash('sha256', $token));
        $lock->acquire(true);
        $request->attributes->set(self::LOCK_ATTRIBUTE, $lock);
    }

    /**
     * Dispatched once the response is ready, whether the refresh succeeded, failed or threw.
     */
    #[AsEventListener(event: KernelEvents::FINISH_REQUEST)]
    public function release(FinishRequestEvent $event): void
    {
        $lock = $event->getRequest()->attributes->get(self::LOCK_ATTRIBUTE);
        if ($lock instanceof LockInterface) {
            $lock->release();
            $event->getRequest()->attributes->remove(self::LOCK_ATTRIBUTE);
        }
    }
}
