<?php

namespace Stancl\Tenancy;

class Tenant
{
    public $uuid;
    public $domain;
    public $databaseName;

    /**
     * Constructor.
     *
     * @param array|string $data
     */
    public function __construct($data)
    {
        $data = is_string($data) ? json_decode($data, true) : (array) $data;

        $this->uuid = $data['uuid'];
        $this->domain = $data['domain'] ?? tenancy()->getTenantById($data['uuid'], 'domain');
        $this->databaseName = $data['database_name'] ?? $this->getDatabaseName($data);
    }

    public function getDatabaseName($uuid = null)
    {
        $uuid = $uuid ?: $this->uuid;
        return config('tenancy.database._prefix_base') . $uuid . config('tenancy.database._suffix');
    }
}
