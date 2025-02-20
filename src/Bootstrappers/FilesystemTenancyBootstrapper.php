<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Foundation\Application;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class FilesystemTenancyBootstrapper implements TenancyBootstrapper
{
    public array $originalDisks = [];
    public string|null $originalAssetUrl;
    public string $originalStoragePath;

    public function __construct(
        protected Application $app,
    ) {
        $this->originalAssetUrl = $this->app['config']['app.asset_url'];
        $this->originalStoragePath = $app->storagePath();

        $this->app['url']->macro('setAssetRoot', function ($root) {
            /** @var UrlGenerator $this */
            $this->assetRoot = $root;

            return $this;
        });
    }

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = $this->suffix($tenant);

        $this->storagePath($suffix);
        $this->assetHelper($suffix);
        $this->forgetDisks();
        $this->scopeCache($suffix);
        $this->scopeSessions($suffix);

        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            $this->diskRoot($disk, $tenant);

            $this->diskUrl(
                $disk,
                str($this->app['config']["tenancy.filesystem.url_override.{$disk}"])
                    ->replace('%tenant%', (string) $tenant->getTenantKey())
                    ->toString(),
            );
        }
    }

    public function revert(): void
    {
        $this->storagePath(false);
        $this->assetHelper(false);
        $this->forgetDisks();
        $this->scopeCache(false);
        $this->scopeSessions(false);

        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            $this->diskRoot($disk, false);
            $this->diskUrl($disk, false);
        }
    }

    protected function suffix(Tenant $tenant): string
    {
        return $this->app['config']['tenancy.filesystem.suffix_base'] . $tenant->getTenantKey();
    }

    protected function storagePath(string|false $suffix): void
    {
        if ($this->app['config']['tenancy.filesystem.suffix_storage_path'] === false) {
            return;
        }

        if ($suffix === false) {
            $this->app->useStoragePath($this->originalStoragePath);
        } else {
            $this->app->useStoragePath($this->tenantStoragePath($suffix));
        }
    }

    protected function tenantStoragePath(string $suffix): string
    {
        return $this->originalStoragePath . "/{$suffix}";
    }

    protected function assetHelper(string|false $suffix): void
    {
        if (! $this->app['config']['tenancy.filesystem.asset_helper_override']) {
            return;
        }

        if ($suffix === false) {
            $this->app['config']['app.asset_url'] = $this->originalAssetUrl;
            $this->app['url']->setAssetRoot($this->originalAssetUrl);

            return;
        }

        if ($this->originalAssetUrl) {
            $this->app['config']['app.asset_url'] = $this->originalAssetUrl . "/$suffix";
            $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);
        } else {
            $this->app['url']->setAssetRoot($this->app['url']->route('stancl.tenancy.asset', ['path' => '']));
        }
    }

    protected function forgetDisks(): void
    {
        Storage::forgetDisk($this->app['config']['tenancy.filesystem.disks']);
    }

    protected function diskRoot(string $disk, Tenant|false $tenant): void
    {
        if ($tenant === false) {
            $this->app['config']["filesystems.disks.$disk.root"] = $this->originalDisks[$disk]['root'];

            return;
        }

        $suffix = $this->suffix($tenant);

        $diskConfig = $this->app['config']["filesystems.disks.{$disk}"];
        $originalRoot = $diskConfig['root'] ?? null;

        $this->originalDisks[$disk]['root'] = $originalRoot;

        if ($override = $this->app['config']["tenancy.filesystem.root_override.{$disk}"]) {
            // This is executed if the disk is in tenancy.filesystem.disks AND has a root_override
            // This behavior is used for local disks.
            $newRoot = str($override)
                ->replace('%storage_path%', $this->tenantStoragePath($suffix))
                ->replace('%original_storage_path%', $this->originalStoragePath)
                ->replace('%tenant%', (string) $tenant->getTenantKey())
                ->toString();
        } else {
            // This is executed if the disk is in tenancy.filesystem.disks but does NOT have a root_override
            // This behavior is used for disks like S3.
            $newRoot = $originalRoot
                ? rtrim($originalRoot, '/') . '/' . $suffix
                : $suffix;
        }

        $this->app['config']["filesystems.disks.{$disk}.root"] = $newRoot;
    }

    protected function diskUrl(string $disk, string|false $override): void
    {
        $diskConfig = $this->app['config']["filesystems.disks.{$disk}"];

        if ($diskConfig['driver'] !== 'local' || $this->app['config']["tenancy.filesystem.url_override.{$disk}"] === null) {
            return;
        }

        if ($override === false) {
            $url = data_get($this->originalDisks, "$disk.url");
            $this->app['config']["filesystems.disks.$disk.url"] = $url;
        } else {
            $this->originalDisks[$disk]['url'] ??= $diskConfig['url'] ?? null;
            $this->app['config']["filesystems.disks.{$disk}.url"] = url($override);
        }
    }

    public function scopeCache(string|false $suffix): void
    {
        if (! $this->app['config']['tenancy.filesystem.scope_cache']) {
            return;
        }

        $storagePath = $suffix
            ? $this->tenantStoragePath($suffix)
            : $this->originalStoragePath;

        $stores = array_filter($this->app['config']['tenancy.cache.stores'], function ($name) {
            $store = $this->app['config']["cache.stores.{$name}"];

            if ($store === null) {
                return false;
            }

            return $store['driver'] === 'file';
        });

        foreach ($stores as $name) {
            $path = $storagePath . '/framework/cache/data';
            $this->app['config']["cache.stores.{$name}.path"] = $path;
            $this->app['config']["cache.stores.{$name}.lock_path"] = $path;

            /** @var \Illuminate\Cache\FileStore $store */
            $store = $this->app['cache']->store($name)->getStore();
            $store->setDirectory($path);
            $store->setLockDirectory($path);
        }
    }

    public function scopeSessions(string|false $suffix): void
    {
        if (! $this->app['config']['tenancy.filesystem.scope_sessions']) {
            return;
        }

        $path = $suffix
            ? $this->tenantStoragePath($suffix) . '/framework/sessions'
            : $this->originalStoragePath . '/framework/sessions';

        if (! is_dir($path)) {
            // Create tenant framework/sessions directory if it does not exist
            mkdir($path, 0755, true);
        }

        $this->app['config']['session.files'] = $path;

        /** @var \Illuminate\Session\SessionManager $sessionManager */
        $sessionManager = $this->app['session'];

        // Since this bootstrapper runs much earlier than the StartSession middleware, this doesn't execute
        // on the average tenant request. It only executes when the context is switched *after* original
        // middleware initialization.
        if (isset($sessionManager->getDrivers()['file'])) {
            $handler = new FileSessionHandler(
                $this->app->make('files'),
                $path,
                $this->app['config']->get('session.lifetime'),
            );

            $sessionManager->getDrivers()['file']->setHandler($handler);
        }
    }
}
