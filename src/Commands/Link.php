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
    protected $description = 'Create the symbolic links configured for the tenancy applications';

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
     * Get the symbolic links that are configured for the application.
     *
     * @return array
     */
    protected function links()
    {
        $disk_urls = config('tenancy.filesystem.url_override');
        $disks = config('tenancy.filesystem.root_override');
        $suffix_base = config('tenancy.filesystem.suffix_base');

        $tenants = $this->option('remove') && filled($this->option('tenants'))
            ? collect($this->option('tenants'))
            : $this->getTenants()->map(function(Tenant $tenant) { return $tenant->getTenantKey(); });

        return $tenants
            ->map(function ($tenant_key) use ($suffix_base, $disk_urls, $disks) {

                $map = [];

                foreach ($disk_urls as $disk => $public_path) {
                    $storage_path = str_replace('%storage_path%', $suffix_base . $tenant_key, $disks[$disk]);
                    $storage_path = storage_path($storage_path);

                    $public_path = str_replace('%tenant_id%', $tenant_key, $public_path);
                    $public_path = public_path($public_path);

                    // make sure storage path exist before we create symlink
                    if (! is_dir($storage_path)) {
                        mkdir($storage_path, 0777, true);
                    }

                    $map[] = [$public_path => $storage_path];
                }

                return $map;

            })->flatten(1)
            ->mapWithKeys(function ($item) {return $item; })
            ->all();
    }

    /**
     * Determine if the provided path is a symlink that can be removed.
     *
     * @param  string  $link
     * @param  bool  $force
     * @return bool
     */
    protected function isRemovableSymlink(string $link, bool $force): bool
    {
        return is_link($link) && $force;
    }
}
