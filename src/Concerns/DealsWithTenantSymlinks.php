<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Exception;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Requires FilesystemTenancyBootstrapper to be enabled, since the tenant symlinks
 * point to the disk roots scoped by the bootstrapper.
 *
 * @see \Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper
 */
trait DealsWithTenantSymlinks
{
    /**
     * Get all possible tenant symlinks, existing or not (array of ['public path' => 'disk root']).
     *
     * Tenants can have a symlink for each local disk that is listed
     * in both tenancy.filesystem.disks and tenancy.filesystem.url_override.
     *
     * This is used for creating all possible tenant symlinks and removing all existing tenant symlinks.
     * The same disk root can be symlinked to multiple public paths, which is why the public path
     * is the array key.
     *
     * @return array<string, string>
     */
    protected function possibleTenantSymlinks(Tenant $tenant): array
    {
        $disks = config('filesystems.disks');
        $urlOverrides = config('tenancy.filesystem.url_override');

        $tenantKey = $tenant->getTenantKey();
        $tenantDisks = tenancy()->run($tenant, fn () => config('filesystems.disks'));

        /** @var array<string, string> $symlinks */
        $symlinks = [];

        foreach ($urlOverrides as $disk => $publicPath) {
            if (! $publicPath) {
                // The disk's URL is not overridden, same as in FilesystemTenancyBootstrapper::diskUrl()
                continue;
            }

            if (! isset($disks[$disk])) {
                continue;
            }

            if ($disks[$disk]['driver'] !== 'local') {
                throw new Exception("Disk $disk is not a local disk. Only local disks can be symlinked.");
            }

            if (! in_array($disk, config('tenancy.filesystem.disks'), true)) {
                // The bootstrapper only scopes disks listed in tenancy.filesystem.disks.
                // Without that, the root stays central and the symlink of every tenant would point to it.
                throw new Exception("Disk $disk is not tenant-aware. Add it to the tenancy.filesystem.disks config to make its root tenant-specific.");
            }

            $publicPath = str_replace('%tenant%', (string) $tenantKey, $publicPath);

            $symlinks[public_path($publicPath)] = $tenantDisks[$disk]['root'];
        }

        return $symlinks;
    }

    /** Determine if the provided path is an existing symlink. */
    protected function symlinkExists(string $link): bool
    {
        return is_link($link);
    }
}
