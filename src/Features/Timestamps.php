<?php

namespace Stancl\Tenancy\Features;

use Illuminate\Support\Facades\Date;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Tenant;
use Stancl\Tenancy\TenantManager;

class Timestamps implements Feature
{
    public function bootstrap(TenantManager $tenantManager)
    {
        $tenantManager->hook('tenant.creating', function ($tm, Tenant $tenant) {
            $tenant->with('created_at', Date::now());
            $tenant->with('updated_at', Date::now());
        });

        $tenantManager->hook('tenant.updating', function ($tm, Tenant $tenant) {
            $tenant->with('updated_at', Date::now());
        });

        $tenantManager->hook('tenant.softDeleting', function ($tm, Tenant $tenant) {
            $tenant->with('deleted_at', Date::now());
        });
    }
}