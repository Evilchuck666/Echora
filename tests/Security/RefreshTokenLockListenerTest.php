<?php

namespace App\Tests\Security;

use App\Security\RefreshTokenLockListener;
use Gesdinet\JWTRefreshTokenBundle\Request\Extractor\RequestBodyExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Concurrency itself can't be reproduced inside one PHPUnit process, so this checks the contract
 * that prevents it: the lock is held on the presented token from before the firewall until the
 * request finishes.
 */
final class RefreshTokenLockListenerTest extends TestCase
{
    private LockFactory $locks;
    private RefreshTokenLockListener $listener;
    private HttpKernelInterface $kernel;

    protected function setUp(): void
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->listener = new RefreshTokenLockListener($this->locks, new RequestBodyExtractor(), 'refresh_token');
        $this->kernel = $this->createStub(HttpKernelInterface::class);
    }

    public function testARefreshHoldsTheLockOnItsTokenUntilTheRequestFinishes(): void
    {
        $request = $this->request('api_refresh_token', 'the-token');

        $this->listener->acquire(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($this->lockFor('the-token')->acquire(), 'A second refresh with the same token has to wait.');
        self::assertTrue($this->lockFor('another-token')->acquire(), 'Refreshes with other tokens are not held up.');

        $this->listener->release(new FinishRequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue($this->lockFor('the-token')->acquire(), 'The lock is released once the request finishes.');
    }

    public function testOtherRoutesTakeNoLock(): void
    {
        $this->listener->acquire(new RequestEvent($this->kernel, $this->request('api_logout', 'the-token'), HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue($this->lockFor('the-token')->acquire());
    }

    public function testARequestWithoutATokenTakesNoLock(): void
    {
        $request = $this->request('api_refresh_token', null);

        $this->listener->acquire(new RequestEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST));

        self::assertFalse($request->attributes->has('_refresh_token_lock'));
    }

    private function request(string $route, ?string $token): Request
    {
        $request = Request::create('/api/token/refresh', 'POST', content: null === $token ? '' : json_encode(['refresh_token' => $token], \JSON_THROW_ON_ERROR));
        $request->attributes->set('_route', $route);

        return $request;
    }

    private function lockFor(string $token): \Symfony\Component\Lock\LockInterface
    {
        return $this->locks->createLock('refresh_token.'.hash('sha256', $token));
    }
}
