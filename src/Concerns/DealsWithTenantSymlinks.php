<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Exception;
use Stancl\Tenancy\Contracts\Tenant;

trait DealsWithTenantSymlinks
{
    /**
     * Get all possible tenant symlinks, existing or not (array of ['public path' => 'storage path']).
     *
     * Tenants can have a symlink for each disk registered in the tenancy.filesystem.url_override config.
     * This is used for creating all possible tenant symlinks and removing all existing tenant symlinks.
     * The same storage path can be symlinked to multiple public paths, which is why the public path
     * is the Collection key.
     *
     * @return array<string, string>
     */
    protected function possibleTenantSymlinks(Tenant $tenant): array
    {
        $disks = config('filesystems.disks');
        $urlOverrides = config('tenancy.filesystem.url_override');
        $rootOverrides = config('tenancy.filesystem.root_override');

        $tenantKey = $tenant->getTenantKey();
        $tenantStoragePath = tenancy()->run($tenant, fn () => storage_path());

        /** @var array<string, string> $symlinks */
        $symlinks = [];

        foreach ($urlOverrides as $disk => $publicPath) {
            if (! isset($disks[$disk])) {
                continue;
            }

            if (! isset($rootOverrides[$disk])) {
                continue;
            }

            if ($disks[$disk]['driver'] !== 'local') {
                throw new Exception("Disk $disk is not a local disk. Only local disks can be symlinked.");
            }

            $publicPath = str_replace('%tenant%', (string) $tenantKey, $publicPath);
            $storagePath = str_replace('%storage_path%', $tenantStoragePath, $rootOverrides[$disk]);

            $symlinks[public_path($publicPath)] = $storagePath;
        }

        return $symlinks;
    }

    /** Determine if the provided path is an existing symlink. */
    protected function symlinkExists(string $link): bool
    {
        return is_link($link);
    }
}
