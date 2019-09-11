<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use ArrayAccess;
use Stancl\Tenancy\Contracts\StorageDriver;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class Tenant implements ArrayAccess
{
    use Traits\HasArrayAccess;

    /**
     * Tenant data. A "cache" of tenant storage.
     *
     * @var array
     */
    public $data = [];

    /**
     * List of domains that belong to the tenant.
     *
     * @var string[]
     */
    public $domains = [];

    /** @var TenantManager */
    protected $manager;

    /** @var StorageDriver */
    protected $storage;

    /**
     * Does this tenant exist in the storage.
     *
     * @var bool
     */
    protected $persisted = false;

    public function __construct(TenantManager $tenantManager, StorageDriver $storage)
    {
        $this->manager = $tenantManager;
        $this->storage = $storage;
    }

    public static function new(): self
    {
        return app(static::class)->withData([
            'id' => static::generateId(), // todo
        ]);
    }

    public static function fromStorage(array $data): self
    {
        return app(static::class)->withData($data)->persisted();
    }

    protected function persisted()
    {
        $this->persisted = true;

        return $this;
    }

    // todo move this to TenantFactory?
    public function withDomains($domains): self
    {
        $domains = (array) $domains;

        $this->domains = $domains;

        return $this;
    }

    public function withData($data): self
    {
        $this->data = $data;

        return $this;
    }

    public function save(): self
    {
        if ($this->persisted) {
            $this->manager->createTenant($this);
        } else {
            $this->manager->updateTenant($this);
        }

        $this->persisted = true;

        return $this;
    }

    public function getDatabaseName()
    {
        return $this['_tenancy_db_name'] ?? $this->app['config']['tenancy.database.prefix'] . $this->uuid . $this->app['config']['tenancy.database.suffix'];
    }

    /**
     * Get a value from tenant storage.
     *
     * @param string|string[] $keys
     * @return void
     */
    public function get($keys)
    {
        if (is_array($keys)) {
            if (array_intersect(array_keys($this->data), $keys)) { // if all keys are present in cache
                return array_reduce($keys, function ($pairs, $key) {
                    $pairs[$key] = $this->data[$key];

                    return $pairs;
                }, []);
            }

            return $this->storage->getMany($keys);
        }

        if (! isset($this->data[$keys])) {
            $this->data[$keys] = $this->storage->get($keys);
        }

        return $this->data[$keys];
    }

    public function put($key, $value = null): self
    {
        // todo something like if ($this->storage->getIdKey() === $key) throw new Exception("Can't override ID")? or should it be overridable?
        // and the responsibility of not overriding domains is up to the storage driver

        if (is_array($key)) {
            $this->storage->putMany($key);
            foreach ($key as $k => $v) { // Add to cache
                $this->data[$k] = $v;
            }
        } else {
            $this->storage->put($key, $value);
            $this->data[$key] = $value;
        }

        return $this;
    }

    public function __get($name)
    {
        return $this->get($name);
    }
}
