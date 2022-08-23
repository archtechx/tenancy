<?php

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\StorageSymlinkRemoved;
use Stancl\Tenancy\Events\RemovingStorageSymlink;

class RemoveStorageSymlinksAction
{
    use DealsWithTenantSymlinks;

    public static function handle($tenants, Closure|null $afterLinkRemoval = null)
    {
        $tenants = $tenants instanceof Tenant ? collect([$tenants]) : $tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach(static::possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
                static::removeLink($publicPath, $tenant, $afterLinkRemoval);
            }
        }
    }

    protected static function removeLink(string $publicPath, Tenant $tenant, Closure|null $afterLinkRemoval)
    {
        if (static::symlinkExists($publicPath)) {
            event(new RemovingStorageSymlink($tenant));

            app()->make('files')->delete($publicPath);

            event(new StorageSymlinkRemoved($tenant));

            if ($afterLinkRemoval) {
                $afterLinkRemoval($publicPath);
            }
        }
    }
}
