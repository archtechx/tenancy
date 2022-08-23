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
     * @return array
     */
    protected static function possibleTenantSymlinks(Tenant $tenant): Collection
    {
        $diskUrls = config('tenancy.filesystem.url_override');
        $disks = config('tenancy.filesystem.root_override');
        $suffixBase = config('tenancy.filesystem.suffix_base');
        $symlinks = [];
        $tenantKey = $tenant->getTenantKey();

        foreach ($diskUrls as $disk => $publicPath) {
            $storagePath = str_replace('%storage_path%', $suffixBase . $tenantKey, $disks[$disk]);
            $storagePath = storage_path($storagePath);

            $publicPath = str_replace('%tenant_id%', $tenantKey, $publicPath);
            $publicPath = public_path($publicPath);

            $symlinks[] = [$publicPath => $storagePath];
        }

        return collect($symlinks)->mapWithKeys(fn ($item) => $item);
    }

    /**
     * Determine if the provided path is an existing symlink.
     */
    protected static function symlinkExists(string $link): bool
    {
        return file_exists($link) && is_link($link);
    }
}
