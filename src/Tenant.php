<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

/**
 * @final Class is subject to breaking changes in minor and patch versions.
 */
final class Tenant
{
    // todo specify id in data

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
     * @var boolean
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

    public function persisted()
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

    public function save()
    {
        if ($this->persisted) {
            $this->manager->addTenant($this);
        } else {
            $this->manager->updateTenant($this);
        }
    }

    public function __get($name)
    {
        return $this->data[$name];
    }
}