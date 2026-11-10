<?php

namespace App\Security;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Remembers, for the grace period, which refresh token replaced which (see RefreshTokenGraceListener).
 *
 * The successor is a live credential, so it is stored encrypted with a key derived from the token it replaced:
 * reading it back needs that token, which only its holder has. The cache (a database table) never holds a token
 * that could be used as it is, like the refresh_tokens table, which only holds hashes.
 */
final readonly class RefreshTokenSuccessors
{
    public function __construct(
        #[Autowire(service: 'cache.refresh_tokens')]
        private CacheItemPoolInterface $pool,
        #[Autowire('%kernel.secret%')]
        private string $secret,
        #[Autowire('%env(int:REFRESH_TOKEN_GRACE_SECONDS)%')]
        private int $graceSeconds,
    ) {
    }

    public function remember(string $replaced, string $successor): void
    {
        if ('' === $replaced || $this->graceSeconds <= 0) {
            return;
        }

        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $item = $this->pool->getItem(self::cacheKey($replaced));
        $item->set(base64_encode($nonce.sodium_crypto_secretbox($successor, $nonce, $this->key($replaced))));
        $item->expiresAfter($this->graceSeconds);
        $this->pool->save($item);
    }

    /**
     * The token that replaced this one, if that happened less than the grace period ago.
     */
    public function recall(string $replaced): ?string
    {
        $item = $this->pool->getItem(self::cacheKey($replaced));
        $stored = $item->isHit() ? $item->get() : null;
        $box = \is_string($stored) ? base64_decode($stored, true) : false;
        if (false === $box || \strlen($box) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }

        $successor = sodium_crypto_secretbox_open(
            substr($box, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($box, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key($replaced),
        );

        return false === $successor ? null : $successor;
    }

    /**
     * A digest, never the token itself: cache keys show up in the database and in dumps.
     */
    public static function cacheKey(string $replaced): string
    {
        return 'refresh_token_successor_'.hash('sha256', $replaced);
    }

    private function key(string $replaced): string
    {
        // The app secret takes part too, so that the token alone, without the server's configuration, isn't enough.
        return sodium_crypto_generichash($replaced, hash('sha256', $this->secret, true), \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
