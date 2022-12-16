<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Illuminate\Cache\ApcStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\ApcWrapper;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Cache\CacheManager as BaseCacheManager;

// todo move to Cache namespace?

class CacheManager extends BaseCacheManager
{
    public static bool $addTags = true;

    /**
     * Add tags and forward the call to the inner cache store.
     *
     * @param string $method
     * @param array $parameters
     */
    public function __call($method, $parameters)
    {
        if (! tenancy()->initialized || ! static::$addTags) {
            return parent::__call($method, $parameters);
        }

        $tags = [config('tenancy.cache.tag_base') . tenant()?->getTenantKey()];

        if ($method === 'tags') {
            $count = count($parameters);

            if ($count !== 1) {
                throw new \Exception("Method tags() takes exactly 1 argument. $count passed.");
            }

            $names = $parameters[0];
            $names = (array) $names; // cache()->tags('foo') https://laravel.com/docs/9.x/cache#removing-tagged-cache-items

            return $this->store()->tags(array_merge($tags, $names));
        }

        return $this->store()->tags($tags)->$method(...$parameters);
    }

    public function refreshStore(string|null $repository = null): void
    {
        Repository::macro('setStore', function ($store) {
            $this->store = $store;
        });

        $this->driver($repository)->setStore($this->createStore($repository ?? $this->getDefaultDriver()));
    }

    protected function createApcStore(array $config): ApcStore
    {
        return new ApcStore(new ApcWrapper, $this->getPrefix($config));
    }

    protected function createArrayStore(array $config): ArrayStore
    {
        return new ArrayStore($config['serialize'] ?? false);
    }

    protected function createFileStore(array $config): FileStore
    {
        return new FileStore($this->app['files'], $config['path'], $config['permission'] ?? null);
    }

    protected function createMemcachedStore(array $config): MemcachedStore
    {
        $memcached = $this->app['memcached.connector']->connect(
            $config['servers'],
            $config['persistent_id'] ?? null,
            $config['options'] ?? [],
            array_filter($config['sasl'] ?? [])
        );

        return new MemcachedStore($memcached, $this->getPrefix($config));
    }

    protected function createRedisStore(array $config): RedisStore
    {
        $connection = $config['connection'] ?? 'default';
        $store = new RedisStore($this->app['redis'], $this->getPrefix($config), $connection);

        return $store->setLockConnection($config['lock_connection'] ?? $connection);
    }

    protected function createNullStore(): NullStore
    {
        return new NullStore;
    }

    public function createStore(string|null $name, array|null $config = null): Store
    {
        $name ??= 'null';
        $storeCreationMethod = 'create' . ucfirst($name) . 'Store';

        return $this->{$storeCreationMethod}($config ?? $this->getConfig($name));
    }
}
