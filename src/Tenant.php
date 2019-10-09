<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use ArrayAccess;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\ForwardsCalls;
use Stancl\Tenancy\Contracts\StorageDriver;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Exceptions\TenantStorageException;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class Tenant implements ArrayAccess
{
    use Traits\HasArrayAccess,
        ForwardsCalls;

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
    public $persisted = false;

    /**
     * Use new() if you don't want to swap dependencies.
     *
     * @param Application $app
     * @param StorageDriver $storage
     * @param TenantManager $tenantManager
     * @param UniqueIdentifierGenerator $idGenerator
     */
    public function __construct(Application $app, StorageDriver $storage, TenantManager $tenantManager, UniqueIdentifierGenerator $idGenerator)
    {
        $this->app = $app;
        $this->storage = $storage->withDefaultTenant($this);
        $this->manager = $tenantManager;
        $this->idGenerator = $idGenerator;
    }

    /**
     * Public constructor.
     *
     * @param Application $app
     * @return self
     */
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

    /**
     * DO NOT CALL THIS METHOD FROM USERLAND. Used by storage
     * drivers to create persisted instances of Tenant.
     *
     * @param array $data
     * @return self
     */
    public static function fromStorage(array $data): self
    {
        return static::new()->withData($data)->persisted(true);
    }

    /**
     * Create a tenant in a single call.
     *
     * @param string|string[] $domains
     * @param array $data
     * @return self
     */
    public static function create($domains, array $data = []): self
    {
        return static::new()->withDomains((array) $domains)->withData($data)->save();
    }

    /**
     * DO NOT CALL THIS METHOD FROM USERLAND UNLESS YOU KNOW WHAT YOU ARE DOING.
     * Set $persisted.
     *
     * @param bool $persisted
     * @return self
     */
    public function persisted(bool $persisted): self
    {
        $this->persisted = $persisted;

        return $this;
    }

    /**
     * Does this model exist in the tenant storage.
     *
     * @return bool
     */
    public function isPersisted(): bool
    {
        return $this->persisted;
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

    /**
     * Unassign all domains from the tenant.
     *
     * @return self
     */
    public function clearDomains(): self
    {
        $this->domains = [];

        return $this;
    }

    /**
     * Set (overwrite) the tenant's domains.
     *
     * @param string|string[] $domains
     * @return self
     */
    public function withDomains($domains): self
    {
        $domains = (array) $domains;

        $this->domains = $domains;

        return $this;
    }

    /**
     * Set (overwrite) tenant data.
     *
     * @param array $data
     * @return self
     */
    public function withData(array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Generate a random ID.
     *
     * @return void
     */
    public function generateId()
    {
        $this->id = $this->idGenerator->generate($this->domains, $this->data);
    }

    /**
     * Write the tenant's state to storage.
     *
     * @return self
     */
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
            $this->manager->deleteTenant($this);
            $this->persisted = false;
        }

        return $this;
    }

    /**
     * Unassign all domains from the tenant and write to storage.
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

    /**
     * Get the tenant's database's name.
     *
     * @return string
     */
    public function getDatabaseName(): string
    {
        return $this->data['_tenancy_db_name'] ?? ($this->app['config']['tenancy.database.prefix'] . $this->id . $this->app['config']['tenancy.database.suffix']);
    }

    /**
     * Get the tenant's database connection's name.
     *
     * @return string
     */
    public function getConnectionName(): string
    {
        return $this->data['_tenancy_db_connection'] ?? 'tenant';
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

    /**
     * Set a value and write to storage.
     *
     * @param string|array<string, mixed> $key
     * @param mixed $value
     * @return self
     */
    public function put($key, $value = null): self
    {
        if ($key === 'id') {
            throw new TenantStorageException("Tenant ids can't be changed.");
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

    /** @alias put */
    public function set($key, $value = null): self
    {
        return $this->put($key, $value);
    }

    /**
     * Set a value.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function with(string $key, $value): self
    {
        $this->data[$key] = $value;

        return $this;
    }

    /**
     * Run a closure inside the tenant's environment.
     *
     * @param Closure $closure
     * @return mixed
     */
    public function run(Closure $closure)
    {
        $originalTenant = $this->manager->getTenant();

        $this->manager->initializeTenancy($this);
        $result = $closure($this);
        $this->manager->endTenancy($this);

        if ($originalTenant) {
            $this->manager->initializeTenancy($originalTenant);
        }

        return $result;
    }

    public function __get($key)
    {
        return $this->get($key);
    }

    public function __set($key, $value)
    {
        if ($key === 'id' && isset($this->data['id'])) {
            throw new TenantStorageException("Tenant ids can't be changed.");
        }

        $this->data[$key] = $value;
    }

    public function __call($method, $parameters)
    {
        if (Str::startsWith($method, 'with')) {
            return $this->with(Str::snake(substr($method, 4)), $parameters[0]);
        }

        static::throwBadMethodCallException($method);
    }
}
