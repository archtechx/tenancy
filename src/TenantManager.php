<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Stancl\Tenancy\Interfaces\StorageDriver;
use Stancl\Tenancy\Traits\BootstrapsTenancy;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Interfaces\UniqueIdentifierGenerator;
use Stancl\Tenancy\Exceptions\CannotChangeUuidOrDomainException;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedException;

final class TenantManager
{
    use BootstrapsTenancy;

    /**
     * The application instance.
     *
     * @var Application
     */
    protected $app;

    /**
     * Storage driver for tenant metadata.
     *
     * @var StorageDriver
     */
    public $storage;

    /**
     * Database manager.
     *
     * @var DatabaseManager
     */
    public $database;

    /**
     * Unique identifier generator.
     *
     * @var UniqueIdentifierGenerator
     */
    protected $generator;

    /**
     * Current tenant.
     *
     * @var array
     */
    public $tenant = [];

    public function __construct(Application $app, StorageDriver $storage, DatabaseManager $database, UniqueIdentifierGenerator $generator)
    {
        $this->app = $app;
        $this->storage = $storage;
        $this->database = $database;
        $this->generator = $generator;
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
            throw new \Exception('No domain supplied nor detected.');
        }

        $tenant = $this->storage->identifyTenant($domain);

        if (! $tenant || ! \array_key_exists('uuid', $tenant) || ! $tenant['uuid']) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $tenant;
    }

    /**
     * Create a tenant.
     *
     * @param string $domain
     * @param array $data
     * @return array
     */
    public function create(string $domain = null, array $data = []): array
    {
        $domain = $domain ?: $this->currentDomain();

        if ($id = $this->storage->getTenantIdByDomain($domain)) {
            throw new \Exception("Domain $domain is already occupied by tenant $id.");
        }

        $tenant = $this->storage->createTenant($domain, $this->generateUniqueIdentifier($domain, $data));
        if ($this->useJson()) {
            $tenant = $this->jsonDecodeArrayValues($tenant);
        }

        if ($data) {
            $this->put($data, null, $tenant['uuid']);

            $tenant = \array_merge($tenant, $data);
        }

        $this->database->create($this->getDatabaseName($tenant));

        return $tenant;
    }

    public function generateUniqueIdentifier(string $domain, array $data)
    {
        return $this->generator->handle($domain, $data);
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

        $tenant = $this->storage->getTenantById($uuid, $fields);
        if ($this->useJson()) {
            $tenant = $this->jsonDecodeArrayValues($tenant);
        }

        return $tenant;
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
        $domain = $domain ? : $this->currentDomain();

        $uuid = $this->getIdByDomain($domain);

        if (\is_null($uuid)) {
            throw new TenantCouldNotBeIdentifiedException($domain);
        }

        return $this->find($uuid, $fields);
    }

    public static function currentDomain(): ?string
    {
        return request()->getHost() ?? null;
    }

    public function getDatabaseName($tenant = []): string
    {
        $tenant = $tenant ?: $this->tenant;

        if ($key = $this->app['config']['tenancy.database_name_key']) {
            if (isset($tenant[$key])) {
                return $tenant[$key];
            }
        }

        return $this->app['config']['tenancy.database.prefix'] . $tenant['uuid'] . $this->app['config']['tenancy.database.suffix'];
    }

    /**
     * Set the tenant property to a JSON decoded version of the tenant's data obtained from storage.
     *
     * @param array $tenant
     * @return array
     */
    public function setTenant(array $tenant): array
    {
        if ($this->useJson()) {
            $tenant = $this->jsonDecodeArrayValues($tenant);
        }

        $this->tenant = $tenant;

        return $tenant;
    }

    /**
     * Reconnects to the default database.
     * @todo More descriptive name?
     *
     * @return void
     */
    public function disconnectDatabase()
    {
        $this->database->disconnect();
    }

    /**
     * Get all tenants.
     *
     * @param array|string $uuids
     * @return \Illuminate\Support\Collection
     */
    public function all($uuids = [])
    {
        $uuids = (array) $uuids;
        $tenants = $this->storage->getAllTenants($uuids);

        if ($this->useJson()) {
            $tenants = \array_map(function ($tenant_array) {
                return $this->jsonDecodeArrayValues($tenant_array);
            }, $tenants);
        }

        return collect($tenants);
    }

    /**
     * Initialize tenancy based on tenant uuid.
     *
     * @param string $uuid
     * @return array
     */
    public function initById(string $uuid): array
    {
        $this->setTenant($this->storage->getTenantById($uuid));
        $this->bootstrap();

        return $this->tenant;
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

        // todo make this cache work with arrays
        if (\array_key_exists('uuid', $this->tenant) && $uuid === $this->tenant['uuid'] &&
            ! \is_array($key) && \array_key_exists($key, $this->tenant)) {
            return $this->tenant[$key];
        }

        if (\is_array($key)) {
            $data = $this->storage->getMany($uuid, $key);
            $data = $this->useJson() ? $this->jsonDecodeArrayValues($data) : $data;

            return $data;
        }

        $data = $this->storage->get($uuid, $key);
        $data = $this->useJson() ? \json_decode($data, true) : $data;

        return $data;
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
        if (\in_array($key, ['uuid', 'domain'], true) || (
            \is_array($key) && (
                \in_array('uuid', \array_keys($key), true) ||
                \in_array('domain', \array_keys($key), true)
            )
        )) {
            throw new CannotChangeUuidOrDomainException;
        }

        if (\is_null($uuid)) {
            if (! isset($this->tenant['uuid'])) {
                throw new \Exception('No UUID supplied (and no tenant is currently identified).');
            }

            $uuid = $this->tenant['uuid'];

            // If $uuid is the uuid of the current tenant, put
            // the value into the $this->tenant array as well.
            $target = &$this->tenant;
        } else {
            $target = []; // black hole
        }

        if (! \is_null($value)) {
            if ($this->useJson()) {
                $data = \json_decode($this->storage->put($uuid, $key, \json_encode($value)), true);
            } else {
                $data = $this->storage->put($uuid, $key, $value);
            }

            return $target[$key] = $data;
        }

        if (! \is_array($key)) {
            throw new \Exception("No value supplied for key $key.");
        }

        foreach ($key as $k => $v) {
            $target[$k] = $v;

            $v = $this->useJson() ? \json_encode($v) : $v;
            $key[$k] = $v;
        }

        $data = $this->storage->putMany($uuid, $key);
        $data = $this->useJson() ? $this->jsonDecodeArrayValues($data) : $data;

        return $data;
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
        return $this->put($key, $value, $uuid);
    }

    protected function jsonDecodeArrayValues(array $array)
    {
        \array_walk($array, function (&$value, $key) {
            if ($value) {
                $value = \json_decode($value, true);
            }
        });

        return $array;
    }

    public function useJson()
    {
        if (\property_exists($this->storage, 'useJson') && $this->storage->useJson === false) {
            return false;
        }

        return true;
    }

    /**
     * Return the identified tenant's attribute(s).
     *
     * @param string $attribute
     * @return mixed
     * @todo Deprecate this in v2.
     */
    public function __invoke($attribute)
    {
        if (\is_null($attribute)) {
            return $this->tenant;
        }

        return $this->get((string) $attribute);
    }
}
