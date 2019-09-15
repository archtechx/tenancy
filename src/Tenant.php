<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use ArrayAccess;
use Illuminate\Foundation\Application;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Exceptions\TenantStorageException;

// todo write tests for updating the tenant

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

    /** @var Application */
    protected $app;

    /** @var StorageDriver */
    protected $storage;

    /** @var TenantManager */
    protected $manager;

    /** @var UniqueIdentifierGenerator */
    protected $idGenerator;

    /**
     * Does this tenant exist in the storage.
     *
     * @var bool
     */
    protected $persisted = false;

    public function __construct(Application $app, StorageDriver $storage, TenantManager $tenantManager, UniqueIdentifierGenerator $idGenerator)
    {
        $this->app = $app;
        $this->storage = $storage->withDefaultTenant($this);
        $this->manager = $tenantManager;
        $this->idGenerator = $idGenerator;
    }

    public static function new(Application $app = null): self
    {
        $app = $app ?? app();

        return new static(
            $app,
            $app[StorageDriver::class],
            $app[TenantManager::class],
            $app[UniqueIdentifierGenerator::class]
        );
    }

    public static function fromStorage(array $data): self
    {
        return static::new()->withData($data)->persisted(true);
    }

    protected function persisted($persisted = null)
    {
        if (gettype($persisted) === 'bool') {
            $this->persisted = $persisted;

            return $this;
        }

        return $this;
    }

    /**
     * Assign domains to the tenant.
     *
     * @param string|string[] $domains
     * @return self
     */
    public function addDomains($domains): self
    {
        $domains = (array) $domains;
        $this->domains = array_merge($this->domains, $domains);

        return $this;
    }

    /**
     * Unassign domains from the tenant.
     *
     * @param string|string[] $domains
     * @return self
     */
    public function removeDomains($domains): self
    {
        $domains = (array) $domains;
        $this->domains = array_diff($this->domains, $domains);

        return $this;
    }

    public function clearDomains(): self
    {
        $this->domains = [];

        return $this;
    }

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

    public function generateId()
    {
        $this->id = $this->idGenerator->handle($this->domains, $this->data);
    }

    public function save(): self
    {
        if (! isset($this->data['id'])) {
            $this->generateId();
        }

        if ($this->persisted) {
            $this->manager->updateTenant($this);
        } else {
            $this->manager->createTenant($this);
        }

        $this->persisted = true;

        return $this;
    }

    /**
     * Delete a tenant from storage.
     *
     * @return self
     */
    public function delete(): self
    {
        if ($this->persisted) {
            $this->tenantManager->deleteTenant($this);
            $this->persisted = false;
        }

        return $this;
    }

    /**
     * Unassign all domains from the tenant.
     *
     * @return self
     */
    public function softDelete(): self
    {
        $this->put('_tenancy_original_domains', $this->domains);
        $this->clearDomains();
        $this->save();

        return $this;
    }

    public function getDatabaseName()
    {
        return $this['_tenancy_db_name'] ?? ($this->app['config']['tenancy.database.prefix'] . $this->id . $this->app['config']['tenancy.database.suffix']);
    }

    public function getConnectionName()
    {
        return $this['_tenancy_db_connection'] ?? 'tenant';
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
            if ((array_intersect(array_keys($this->data), $keys) === $keys) ||
                ! $this->persisted) { // if all keys are present in cache

                return array_reduce($keys, function ($pairs, $key) {
                    $pairs[$key] = $this->data[$key] ?? null;

                    return $pairs;
                }, []);
            }

            return $this->storage->getMany($keys);
        }

        // single key
        $key = $keys;

        if (! isset($this->data[$key]) && $this->persisted) {
            $this->data[$key] = $this->storage->get($key);
        }

        return $this->data[$key];
    }

    public function put($key, $value = null): self
    {
        if ($this->storage->getIdKey() === $key) {
            throw new TenantStorageException("The tenant's id can't be changed.");
        }

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

    public function __get($key)
    {
        return $this->get($key);
    }

    public function __set($key, $value)
    {
        $this->data[$key] = $value;
    }
}
