<?php

declare(strict_types=1);

use Stancl\Tenancy\TenantManager;

if (! \function_exists('tenancy')) {
    function tenancy($key = null)
    {
        if ($key) {
            return app(TenantManager::class)->tenant[$key] ?? null;
        }

        return app(TenantManager::class);
    }
}

if (! \function_exists('tenant')) {
    function tenant($key = null)
    {
        return tenancy($key);
    }
}

if (! \function_exists('tenant_asset')) {
    function tenant_asset($asset)
    {
        return route('stancl.tenancy.asset', ['asset' => $asset]);
    }
}
