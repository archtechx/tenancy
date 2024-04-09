<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Session\CacheBasedSessionHandler;
use Illuminate\Session\SessionManager;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Makes cache tenant-aware by applying a prefix.
 */
class CacheTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var Closure(Tenant, string): string */
    public static Closure|null $prefixGenerator = null;

    /** @var array<string, string> */
    protected array $originalPrefixes = [];

    public function __construct(
        protected ConfigRepository $config,
        protected CacheManager $cache,
        protected SessionManager $session,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        foreach ($this->getCacheStores() as $name) {
            $store = $this->cache->driver($name)->getStore();

            $this->originalPrefixes[$name] = $store->getPrefix();
            $this->setCachePrefix($store, $this->generatePrefix($tenant, $name));
        }

        if ($this->shouldScopeSessions()) {
            $name = $this->getSessionCacheStoreName();
            $handler = $this->session->driver()->getHandler();

            if ($handler instanceof CacheBasedSessionHandler) {
                // The CacheBasedSessionHandler is constructed with a *clone* of
                // an existing cache store, so we need to set the prefix separately.
                $store = $handler->getCache()->getStore();

                // We also don't need to set the original prefix, since the cache store
                // is implicitly added to the configured cache stores when session scoping
                // is enabled.

                $this->setCachePrefix($store, $this->generatePrefix($tenant, $name));
            }
        }
    }

    public function revert(): void
    {
        foreach ($this->getCacheStores() as $name) {
            $store = $this->cache->driver($name)->getStore();

            $this->setCachePrefix($store, $this->originalPrefixes[$name]);
        }

        if ($this->shouldScopeSessions()) {
            $name = $this->getSessionCacheStoreName();
            $handler = $this->session->driver()->getHandler();

            if ($handler instanceof CacheBasedSessionHandler) {
                $store = $handler->getCache()->getStore();

                $this->setCachePrefix($store, $this->originalPrefixes[$name]);
            }
        }
    }

    protected function getSessionCacheStoreName(): string
    {
        return $this->config->get('session.store') ?? $this->config->get('session.driver');
    }

    protected function shouldScopeSessions(): bool
    {
        // We don't want to scope sessions if:
        //   1. The user has disabled session scoping via this bootstrapper, AND
        //   2. The session driver hasn't been instantiated yet (if this is the case,
        //      it will be instantiated later by cloning an existing cache store
        //      that will have already been prefixed in this bootstrapper).
        return $this->config->get('tenancy.cache.scope_sessions', true)
            && count($this->session->getDrivers()) !== 0;
    }

    /** @return string[] */
    protected function getCacheStores(): array
    {
        $names = $this->config->get('tenancy.cache.stores');

        if (
            $this->config->get('tenancy.cache.scope_sessions', true) &&
            in_array($this->config->get('session.driver'), ['redis', 'memcached', 'dynamodb', 'apc'], true)
        ) {
            $names[] = $this->getSessionCacheStoreName();
        }

        $names = array_unique($names);

        return array_filter($names, function ($name) {
            $store = $this->config->get("cache.stores.{$name}");

            if ($store === null || $store['driver'] === 'file') {
                return false;
            }

            if ($store['driver'] === 'array') {
                throw new Exception('Cache store [' . $name . '] is not supported by this bootstrapper.');
            }

            return true;
        });
    }

    protected function setCachePrefix(Store $store, string|null $prefix): void
    {
        if (! method_exists($store, 'setPrefix')) {
            throw new Exception('Cache store [' . get_class($store) . '] does not support setting a prefix.');
        }

        $store->setPrefix($prefix);
    }

    public function generatePrefix(Tenant $tenant, string $store): string
    {
        return static::$prefixGenerator
            ? (static::$prefixGenerator)($tenant, $store)
            : $this->originalPrefixes[$store] . str($this->config->get('tenancy.cache.prefix'))
                ->replace('%tenant%', (string) $tenant->getTenantKey())->toString();
    }

    /**
     * Set a custom prefix generator.
     *
     * The first argument is the tenant, the second argument is the cache store name.
     *
     * @param Closure(Tenant, string): string $prefixGenerator
     */
    public static function generatePrefixUsing(Closure $prefixGenerator): void
    {
        static::$prefixGenerator = $prefixGenerator;
    }
}
