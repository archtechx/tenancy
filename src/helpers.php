<?php

use Stancl\Tenancy\TenantManager;

if (! function_exists('tenancy')) {
    function tenancy($key = null)
    {
        if ($key) {
            return app(TenantManager::class)->tenant[$key];
        }

        return app(TenantManager::class);
    }
}

if (!function_exists('tenant')) {
    function tenant($key = null)
    {
        return tenancy($key);
    }
}
