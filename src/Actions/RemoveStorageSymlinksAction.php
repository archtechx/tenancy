<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\RemovingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkRemoved;

class RemoveStorageSymlinksAction
{
    use DealsWithTenantSymlinks;

    public static function handle(Tenant|Collection|LazyCollection $tenants): void
    {
        $tenants = $tenants instanceof Tenant ? collect([$tenants]) : $tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach (static::possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
                static::removeLink($publicPath, $tenant);
            }
        }
    }

    protected static function removeLink(string $publicPath, Tenant $tenant): void
    {
        if (static::symlinkExists($publicPath)) {
            event(new RemovingStorageSymlink($tenant));

            app()->make('files')->delete($publicPath);

            event(new StorageSymlinkRemoved($tenant));
        }
    }
}
