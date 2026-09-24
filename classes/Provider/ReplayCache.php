<?php

declare(strict_types=1);

namespace Grav\Plugin\EmailMailgun\Provider;

/**
 * The Mailgun tokens this site has already accepted, so a captured inbound
 * post cannot be sent again while its timestamp is still fresh.
 *
 * Mailgun's own advice (`securing-webhooks`): "cache the token value locally
 * and not honor any subsequent request with the same token". A token is kept
 * for as long as its timestamp could still pass the freshness check.
 *
 * ## Where it is kept
 *
 * In Grav's cache when Grav is running: {@see fromGrav()} takes the PSR-16
 * cache Grav builds on its own configured adapter (`Cache::getSimpleCache()`,
 * there since Grav 1.7), which is used whether or not page caching is switched
 * on and is shared by every request on a file, APCu, Redis or Memcached
 * driver. `bin/grav clear` empties it, which at worst reopens the fifteen
 * minutes a token was fresh for anyway.
 *
 * Anything with PSR-16's `has()` and `set()` can be passed instead. With no
 * store at all, tokens are kept in memory for the life of this object, which
 * is what the tests use and all a site without Grav's cache gets: the
 * timestamp window still limits a replay, and a consumer's own dedupe on the
 * message id still catches the copy.
 */
final class ReplayCache
{
    private const PREFIX = 'email-mailgun.inbound-token.';

    /** @var array<string, int> key => expiry */
    private array $memory = [];

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param object|null            $store anything with PSR-16 `has($key)` and `set($key, $value, $ttl)`
     * @param (\Closure(): int)|null $clock the current Unix time; tests pass their own
     */
    public function __construct(
        private readonly ?object $store = null,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** Grav's cache when Grav is running, memory when it is not. Never throws. */
    public static function fromGrav(): self
    {
        try {
            if (class_exists(\Grav\Common\Grav::class)) {
                $cache = \Grav\Common\Grav::instance()['cache'] ?? null;
                if (\is_object($cache) && method_exists($cache, 'getSimpleCache')) {
                    return new self($cache->getSimpleCache());
                }
            }
        } catch (\Throwable) {
        }

        return new self();
    }

    /**
     * Whether this token was accepted before. Records it when it was not, so
     * the answer for the same token is false once and true after that.
     */
    public function seen(string $token, int $ttl): bool
    {
        $key = self::PREFIX . hash('sha256', $token);

        if ($this->store !== null && method_exists($this->store, 'has') && method_exists($this->store, 'set')) {
            try {
                if ($this->store->has($key)) {
                    return true;
                }
                $this->store->set($key, 1, $ttl);

                return false;
            } catch (\Throwable) {
                // A cache that fails is not a reason to refuse mail; fall back to memory.
            }
        }

        $now = ($this->clock)();
        foreach ($this->memory as $stored => $expires) {
            if ($expires <= $now) {
                unset($this->memory[$stored]);
            }
        }

        if (isset($this->memory[$key])) {
            return true;
        }
        $this->memory[$key] = $now + $ttl;

        return false;
    }
}
