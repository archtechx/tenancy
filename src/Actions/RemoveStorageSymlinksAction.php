<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithTenantSymlinks;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\RemovingStorageSymlink;
use Stancl\Tenancy\Events\StorageSymlinkRemoved;

class RemoveStorageSymlinksAction
{
    use DealsWithTenantSymlinks;

    /**
     * @param Tenant|Collection<covariant int|string, Tenant&\Illuminate\Database\Eloquent\Model>|LazyCollection<covariant int|string, Tenant&\Illuminate\Database\Eloquent\Model> $tenants
     */
    public function __invoke(Tenant|Collection|LazyCollection $tenants): void
    {
        $tenants = $tenants instanceof Tenant ? collect([$tenants]) : $tenants;

        /** @var Tenant $tenant */
        foreach ($tenants as $tenant) {
            foreach ($this->possibleTenantSymlinks($tenant) as $publicPath => $storagePath) {
                $this->removeLink($publicPath, $tenant);
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
