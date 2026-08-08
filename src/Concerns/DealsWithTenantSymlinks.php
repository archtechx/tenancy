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
     * Tenants can have a symlink for each disk registered in the tenancy.filesystem.url_override config.
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
        $rootOverrides = config('tenancy.filesystem.root_override');

        $tenantKey = $tenant->getTenantKey();
        $tenantDisks = tenancy()->run($tenant, fn () => config('filesystems.disks'));

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
