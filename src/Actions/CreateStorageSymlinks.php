<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\CreatingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkCreated;

class CreateStorageSymlinks
{
    use DealsWithTenantSymlinks;

    public function __construct(protected Tenant|Collection|LazyCollection $tenants, protected bool $relativeLink = false, protected bool $force = false)
    {
    }

    public function handle(): void
    {
        $tenants = $this->tenants instanceof Tenant ? collect([$this->tenants]) : $this->tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach ($this->possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
                $this->createLink((string) $publicPath, (string) $storagePath, $tenant);
            }
        }
    }

    protected function createLink(string $publicPath, string $storagePath, Tenant $tenant): void
    {
        event(new CreatingStorageSymlink($tenant));

        if ($this->symlinkExists($publicPath)) {
            // If $force isn't passed, don't overwrite the existing symlink
            throw_if(! $this->force, new Exception("The [$publicPath] link already exists."));

            app()->make('files')->delete($publicPath);
        }

        // Make sure the storage path exists before we create a symlink
        if (! is_dir($storagePath)) {
            mkdir($storagePath, 0777, true);
        }

        if ($this->relativeLink) {
            app()->make('files')->relativeLink($storagePath, $publicPath);
        } else {
            app()->make('files')->link($storagePath, $publicPath);
        }

        event((new StorageSymlinkCreated($tenant)));
    }
}
