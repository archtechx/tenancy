<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\RemovingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkRemoved;

class RemoveStorageSymlinks
{
    use DealsWithTenantSymlinks;

    public function __construct(protected Tenant|Collection|LazyCollection $tenants)
    {
    }

    public function handle(): void
    {
        $tenants = $this->tenants instanceof Tenant ? collect([$this->tenants]) : $this->tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach ($this->possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
                $this->removeLink((string) $publicPath, $tenant);
            }
        }
    }

    protected function removeLink(string $publicPath, Tenant $tenant): void
    {
        if ($this->symlinkExists($publicPath)) {
            event(new RemovingStorageSymlink($tenant));

            app()->make('files')->delete($publicPath);

            event(new StorageSymlinkRemoved($tenant));
        }
    }
}
