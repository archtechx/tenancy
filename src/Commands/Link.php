<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;

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
        $tenants = collect($this->option('tenants')) ?? $this->getTenants()->map->getTenantKey();
        $links = $tenants->flatMap(fn ($tenantKey) => $this->getPossibleTenantSymlinks($tenantKey))
            ->mapWithKeys(fn ($item) => $item)
            ->all();

        if ($this->option('remove')) {
            $this->removeLinks($links);
        } else {
            $this->createLinks($links);
        }
    }

    protected function removeLinks(array $links)
    {
        foreach ($links as $publicPath => $storagePath) {
            $this->removeLink($publicPath);
        }

        $this->info('The links have been removed.');
    }

    protected function createLinks(array $links)
    {
        foreach ($links as $link => $storagePath) {
            $this->createLink($link, $storagePath);
        }

        $this->info('The links have been created.');
    }

    protected function removeLink(string $publicPath)
    {
        if ($this->symlinkExists($publicPath)) {
            $this->laravel->make('files')->delete($publicPath);

            $this->info("The [$publicPath] link has been removed.");
        }
    }

    protected function createLink(string $publicPath, string $storagePath)
    {
        if ($this->symlinkExists($publicPath)) {
            // If the 'force' option isn't passed, don't overwrite the existing symlink
            if (! $this->option('force')) {
                $this->error("The [$publicPath] link already exists.");

                return;
            }

            $this->laravel->make('files')->delete($publicPath);
        }

        // Make sure the storage path exists before we create a symlink
        if (! is_dir($storagePath)) {
            mkdir($storagePath, 0777, true);
        }

        if ($this->option('relative')) {
            $this->laravel->make('files')->relativeLink($storagePath, $publicPath);
        } else {
            $this->laravel->make('files')->link($storagePath, $publicPath);
        }

        $this->info("The [$publicPath] link has been connected to [$storagePath].");
    }

    /**
     * Get all possible tenant symlinks, existing or not (array of ['public path' => 'storage path']).
     *
     * @return array
     */
    protected function getPossibleTenantSymlinks(int|string $tenantKey)
    {
        $diskUrls = config('tenancy.filesystem.url_override');
        $disks = config('tenancy.filesystem.root_override');
        $suffixBase = config('tenancy.filesystem.suffix_base');
        $symlinks = [];

        foreach ($diskUrls as $disk => $publicPath) {
            $storagePath = str_replace('%storage_path%', $suffixBase . $tenantKey, $disks[$disk]);
            $storagePath = storage_path($storagePath);

            $publicPath = str_replace('%tenant_id%', $tenantKey, $publicPath);
            $publicPath = public_path($publicPath);

            $symlinks[] = [$publicPath => $storagePath];
        }

        return $symlinks;
    }

    /**
     * Determine if the provided path is an existing symlink.
     */
    protected function symlinkExists(string $link): bool
    {
        return file_exists($link) && is_link($link);
    }
}
