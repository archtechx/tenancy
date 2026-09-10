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
     * Should the directories created for nested symlinks be removed along with the symlink.
     *
     * Before enabling this, make sure you understand the removeLink() method and its implications.
     *
     * @see CreateStorageSymlinksAction
     */
    public static bool $removeNestedDirectories = false;

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
        if (! $this->symlinkExists($publicPath)) {
            return;
        }

        $files = app()->make('files');

        event(new RemovingStorageSymlink($tenant));

        $files->delete($publicPath);

        event(new StorageSymlinkRemoved($tenant));

        if (! static::$removeNestedDirectories) {
            return;
        }

        $publicRoot = realpath(public_path());
        $directory = realpath(dirname($publicPath));

        if ($publicRoot === false || $directory === false) {
            return;
        }

        // Remove the directories CreateStorageSymlinksAction created for the symlink
        // until a non-empty one is reached.
        while (str_starts_with($directory, $publicRoot . DIRECTORY_SEPARATOR) && $files->isEmptyDirectory($directory)) {
            $files->deleteDirectory($directory);

            $directory = dirname($directory);
        }
    }
}
