<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Contracts\Tenant;

class Link extends Command
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
                {--force : Recreate existing symbolic links}
                {--remove : Remove symbolic links}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create symbolic links for tenants.';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        $relative = $this->option('relative');

        if ($this->option('remove')) {
            foreach ($this->links() as $link => $target) {
                if (is_link($link)) {
                    $this->laravel->make('files')->delete($link);

                    $this->info("The [$link] link has been removed.");
                }
            }

            $this->info('The links have been removed.');

            return;
        }

        foreach ($this->links() as $link => $target) {
            if (file_exists($link) && ! $this->isRemovableSymlink($link, $this->option('force'))) {
                $this->error("The [$link] link already exists.");
                continue;
            }

            if (is_link($link)) {
                $this->laravel->make('files')->delete($link);
            }

            if ($relative) {
                $this->laravel->make('files')->relativeLink($target, $link);
            } else {
                $this->laravel->make('files')->link($target, $link);
            }

            $this->info("The [$link] link has been connected to [$target].");
        }

        $this->info('The links have been created.');
    }

    /**
     * Create symbolic links using the tenancy.filesystem config.
     *
     * @return array
     */
    protected function links()
    {
        $diskUrls = config('tenancy.filesystem.url_override');
        $disks = config('tenancy.filesystem.root_override');
        $suffixBase = config('tenancy.filesystem.suffix_base');

        $tenants = $this->option('remove') && filled($this->option('tenants'))
            ? collect($this->option('tenants'))
            : $this->getTenants()->map(function (Tenant $tenant) {
                return $tenant->getTenantKey();
            });

        return $tenants->map(function ($tenantKey) use ($suffixBase, $diskUrls, $disks) {
            $symLinks = [];

            foreach ($diskUrls as $disk => $publicPath) {
                $storagePath = str_replace('%storage_path%', $suffixBase . $tenantKey, $disks[$disk]);
                $storagePath = storage_path($storagePath);

                $publicPath = str_replace('%tenant_id%', $tenantKey, $publicPath);
                $publicPath = public_path($publicPath);

                // Make sure the storage path exists before we create a symlink
                if (! is_dir($storagePath)) {
                    mkdir($storagePath, 0777, true);
                }

                $symLinks[] = [$publicPath => $storagePath];
            }

            return $symLinks;
        })->flatten(1)
        ->mapWithKeys(fn ($item) => $item)
        ->all();
    }

    /**
     * Determine if the provided path is a removable symlink.
     */
    protected function isRemovableSymlink(string $link, bool $force): bool
    {
        return is_link($link) && $force;
    }
}
