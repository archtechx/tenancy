<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\RemovingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkRemoved;

class RemoveStorageSymlinksAction
{
    use DealsWithTenantSymlinks;

    public static function handle($tenants, Closure|null $afterLinkRemoval = null)
    {
        $tenants = $tenants instanceof Tenant ? collect([$tenants]) : $tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach (static::possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
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
