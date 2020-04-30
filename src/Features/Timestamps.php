<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Config\Repository;
use Illuminate\Support\Facades\Date;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantManager;

class Timestamps implements Feature
{
    /** @var Repository */
    protected $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    public function bootstrap(TenantManager $tenantManager): void
    {
        $tenantManager->hook('tenant.creating', function ($tm, Tenant $tenant) {
            $tenant->with('created_at', $this->now());
            $tenant->with('updated_at', $this->now());
        });

        $tenantManager->hook('tenant.updating', function ($tm, Tenant $tenant) {
            $tenant->with('updated_at', $this->now());
        });

        $tenantManager->hook('tenant.softDeleting', function ($tm, Tenant $tenant) {
            $tenant->with('deleted_at', $this->now());
        });
    }

    public function now(): string
    {
        // Add this key to your tenancy.php config if you need to change the format.
        return Date::now()->format(
            $this->config->get('tenancy.timestamp_format') ?? 'c' // ISO 8601
        );
    }
}
