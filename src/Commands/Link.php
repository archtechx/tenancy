<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Foundation\Console\StorageLinkCommand;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Contracts\Tenant;

class Link extends StorageLinkCommand
{
    use HasATenantsOption;

    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'tenants:link
                {--tenants=* : The tenant(s) to run the command for. Default: all}
                {--relative : Create the symbolic link using relative paths}
                {--force : Recreate existing symbolic links}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create the symbolic links configured for the tenancy applications';

    /**
     * Get the symbolic links that are configured for the application.
     *
     * @return array
     */
    protected function links()
    {
        $disk_urls = config('tenancy.filesystem.url_override');
        $disks = config('tenancy.filesystem.root_override');
        $suffix_base = config('tenancy.filesystem.suffix_base');

        return $this->getTenants()
            ->map(function (Tenant $tenant) use ($suffix_base, $disk_urls, $disks) {

                $map = [];

                foreach ($disk_urls as $disk => $public_path) {
                    $storage_path = str_replace('%storage_path%', $suffix_base . $tenant['id'], $disks[$disk]);
                    $storage_path = storage_path($storage_path);

                    $public_path = str_replace('%tenant_id%', $tenant['id'], $public_path);
                    $public_path = public_path($public_path);

                    // make sure storage path exist before we create symlink
                    if (! is_dir($storage_path)) {
                        mkdir($storage_path, 0777, true);
                    }

                    $map[] = [$public_path => $storage_path];
                }

                return $map;

            })->flatten(1)
            ->mapWithKeys(fn ($item) => $item)
            ->all();
    }
}
