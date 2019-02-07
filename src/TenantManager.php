<?php

namespace Stancl\Tenancy;

use Illuminate\Support\Facades\Redis;
use Stancl\Tenancy\Interfaces\StorageDriver;
use Stancl\Tenancy\Traits\BootstrapsTenancy;

class TenantManager
{
    use BootstrapsTenancy;

    /**
     * The application instance.
     *
     * @var \Illuminate\Contracts\Foundation\Application|\Illuminate\Foundation\Application
     */
    private $app;

    /**
     * Storage driver for tenant metadata.
     *
     * @var StorageDriver
     */
    private $storage;
    
    /**
     * Database manager.
     *
     * @var DatabaseManager
     */
    private $database;

    /**
     * Current tenant.
     *
     * @var array
     */
    public $tenant;

    public function __construct($app, StorageDriver $storage, DatabaseManager $database)
    {
        $this->app = $app;
        $this->storage = $storage;
        $this->database = $database;
    }

    public function init(string $domain = null): array
    {
        $this->setTenant($this->identify($domain));
        $this->bootstrap();
        return $this->tenant;
    }

    public function identify(string $domain = null): array
    {
        $domain = $domain ?: $this->currentDomain();

        if (! $domain) {
            throw new \Exception("No domain supplied nor detected.");
        }

        $tenant = $this->storage->identifyTenant($domain);

        if (! $tenant || ! array_key_exists('uuid', $tenant) || ! $tenant['uuid']) {
            throw new \Exception("Tenant could not be identified on domain {$domain}.");
        }

        return $tenant;
    }

    public function create(string $domain = null): array
    {
        $domain = $domain ?: $this->currentDomain();

        if ($id = $this->storage->getTenantIdByDomain($domain)) {
            throw new \Exception("Domain $domain is already occupied by tenant $id.");
        }

        $tenant = $this->storage->createTenant($domain, \Webpatser\Uuid\Uuid::generate(1, $domain));
        $this->database->create($this->getDatabaseName($tenant));
        
        return $tenant;
    }

    public function delete(string $uuid): bool
    {
        return $this->storage->deleteTenant($uuid);
    }

    /**
     * Return an array with information about a tenant based on his uuid.
     *
     * @param string $uuid
     * @param array|string $fields
     * @return array
     */
    public function getTenantById(string $uuid, $fields = [])
    {
        $fields = (array) $fields;
        return $this->storage->getTenantById($uuid, $fields);
    }

    /**
     * Alias for getTenantById().
     *
     * @param string $uuid
     * @param array|string $fields
     * @return array
     */
    public function find(string $uuid, $fields = [])
    {
        return $this->getTenantById($uuid, $fields);
    }

    /**
     * Get tenant uuid based on the domain that belongs to him.
     *
     * @param string $domain
     * @return string|null
     */
    public function getTenantIdByDomain(string $domain = null): ?string
    {
        $domain = $domain ?: $this->currentDomain();

        return $this->storage->getTenantIdByDomain($domain);
    }

    /**
     * Alias for getTenantIdByDomain().
     *
     * @param string $domain
     * @return string|null
     */
    public function getIdByDomain(string $domain = null)
    {
        return $this->getTenantIdByDomain($domain);
    }

    /**
     * Get tenant information based on his domain.
     *
     * @param string $domain
     * @param mixed $fields
     * @return array
     */
    public function findByDomain(string $domain = null, $fields = [])
    {
        $domain = $domain ?: $this->currentDomain();

        return $this->find($this->getIdByDomain($domain), $fields);
    }

    public static function currentDomain(): ?string
    {
        return request()->getHost() ?? null;
    }

    public function getDatabaseName($tenant = []): string
    {
        $tenant = $tenant ?: $this->tenant;
        return config('tenancy.database.prefix') . $tenant['uuid'] . config('tenancy.database.suffix');
    }

    public function getStoragePath($tenant = []): ?string
    {
        $tenant = $tenant ?: $this->tenant;
        return config('tenancy.filesystem.suffix_base') . $tenant['uuid'];
    }

    public function setTenant(array $tenant): array
    {
        $this->tenant = $tenant;
        
        return $tenant;
    }

    /**
     * Get all tenants.
     *
     * @param array|string $uuids
     * @return array
     */
    public function all($uuids = [])
    {
        $uuid = (array) $uuids;
        return collect($this->storage->getAllTenants($uuids));
    }

    public function actAsId(string $uuid): array
    {
        return $this->setTenant($this->storage->getTenantById($uuid));
    }

    public function actAsDomain(string $domain): string
    {
        return $this->init($domain);
    }

    /**
     * Get a value from the storage for a tenant.
     *
     * @param string|array $key
     * @param string $uuid
     * @return mixed
     */
    public function get($key, string $uuid = null)
    {
        $uuid = $uuid ?: $this->tenant['uuid'];

        if (is_array($key)) {
            return $this->storage->getMany($uuid, $key);
        }

        return $this->storage->get($this->tenant['uuid'], $key);
    }

    /**
     * Puts a value into the storage for a tenant.
     *
     * @param string|array $key
     * @param mixed $value
     * @param string uuid
     * @return mixed
     */
    public function put($key, $value = null, string $uuid = null)
    {
        if (is_null($uuid)) {
            if (! isset($this->tenant['uuid'])) {
                throw new \Exception("No UUID supplied (and no tenant is currently identified).");
            }
            
            $uuid = $this->tenant['uuid'];

            // If $uuid is the uuid of the current tenant, put
            // the value into the $this->tenant array as well.
            $target = &$this->tenant;
        } else {
            $target = []; // black hole
        }

        if (! is_null($value)) {
            return $target[$key] = $this->storage->put($uuid, $key, $value);
        }

        if (! is_array($key)) {
            throw new \Exception("No value supplied for key $key.");
        }

        return $target[$key] = $this->storage->putMany($uuid, $key);
    }

    /**
     * Alias for put().
     *
     * @param string|array $key
     * @param mixed $value
     * @param string $uuid
     * @return mixed
     */
    public function set($key, $value = null, string $uuid = null)
    {
        $uuid = $uuid ?: $this->tenant['uuid'];

        return $this->put($this->put($key, $value));
    }

    /**
     * Return the identified tenant's attribute(s).
     *
     * @param string $attribute
     * @return mixed
     */
    public function __invoke($attribute)
    {
        if (is_null($attribute)) {
            return $this->tenant;
        }
        
        return $this->tenant[(string) $attribute];
    }
}
