<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\CreatingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkCreated;

class CreateStorageSymlinksAction
{
    use DealsWithTenantSymlinks;

    public static function handle(Tenant|Collection|LazyCollection $tenants, bool $relativeLink = false, bool $force = false): void
    {
        $tenants = $tenants instanceof Tenant ? collect([$tenants]) : $tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach (static::possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
                static::createLink($publicPath, $storagePath, $tenant, $relativeLink, $force);
            }
        }
    }

    protected static function createLink(string $publicPath, string $storagePath, Tenant $tenant, bool $relativeLink, bool $force): void
    {
        event(new CreatingStorageSymlink($tenant));

        if (static::symlinkExists($publicPath)) {
            // If $force isn't passed, don't overwrite the existing symlink
            throw_if(! $force, new Exception("The [$publicPath] link already exists."));

            app()->make('files')->delete($publicPath);
        }

        // Make sure the storage path exists before we create a symlink
        if (! is_dir($storagePath)) {
            mkdir($storagePath, 0777, true);
        }

        if ($relativeLink) {
            app()->make('files')->relativeLink($storagePath, $publicPath);
        } else {
            app()->make('files')->link($storagePath, $publicPath);
        }

        event((new StorageSymlinkCreated($tenant)));
    }
}
