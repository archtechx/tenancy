<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use ArrayAccess;

// todo tenant storage

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class Tenant implements ArrayAccess, Contracts\Tenant
{
    use Traits\HasArrayAccess;

    /**
     * Tenant data.
     *
     * @var array
     */
    public $data = [];

    /**
     * List of domains that belong to the tenant.
     *
     * @var string[]
     */
    public $domains;

    /**
     * @var TenantManager
     */
    private $manager;

    /**
     * Does this tenant exist in the storage.
     *
     * @var bool
     */
    private $persisted = false;

    public function __construct(TenantManager $tenantManager)
    {
        $this->manager = $tenantManager;
    }

    public static function new(): self
    {
        return app(static::class);
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
            $this->manager->addTenant($this);
        } else {
            $this->manager->updateTenant($this);
        }

        $this->persisted = true;

        return $this;
    }

    public function getDatabaseName()
    {
        return $this['_tenancy_database'] ?? $this->app['config']['tenancy.database.prefix'] . $this->uuid . $this->app['config']['tenancy.database.suffix'];
    }

    public function __get($name)
    {
        return $this->data[$name] ?? null;
    }
}
