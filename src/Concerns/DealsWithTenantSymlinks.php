<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Support\Collection;
use Stancl\Tenancy\Database\Models\Tenant;

trait DealsWithTenantSymlinks
{
    /**
     * Get all possible tenant symlinks, existing or not (array of ['public path' => 'storage path']).
     *
     * Tenants can have a symlink for each disk registered in the tenancy.filesystem.url_override config.
     *
     * This is used for creating all possible tenant symlinks and removing all existing tenant symlinks.
     */
    protected static function possibleTenantSymlinks(Tenant $tenant): Collection
    {
        $diskUrls = config('tenancy.filesystem.url_override');
        $disks = config('tenancy.filesystem.root_override');
        $suffixBase = config('tenancy.filesystem.suffix_base');
        $symlinks = collect();
        $tenantKey = $tenant->getTenantKey();

        foreach ($diskUrls as $disk => $publicPath) {
            $storagePath = storage_path(str_replace('%storage_path%', $suffixBase . $tenantKey, $disks[$disk]));
            $publicPath = public_path(str_replace('%tenant_id%', $tenantKey, $publicPath));

            tenancy()->central(function () use ($symlinks, $publicPath, $storagePath) {
                $symlinks->push([$publicPath => $storagePath]);
            });
        }

        return $symlinks->mapWithKeys(fn ($item) => $item);
    }

    /** Determine if the provided path is an existing symlink. */
    protected static function symlinkExists(string $link): bool
    {
        return file_exists($link) && is_link($link);
    }
}
