<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Exception;
use Illuminate\Foundation\Application;
use Illuminate\Session\FileSessionHandler;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class FilesystemTenancyBootstrapper implements TenancyBootstrapper
{
    public array $originalDisks = [];
    protected array $originalCachePaths = [];
    protected array $originalCacheLockPaths = [];
    protected string|null $originalSessionPath = null;
    public string|null $originalAssetUrl;
    public string $originalStoragePath;

    public function __construct(
        protected Application $app,
    ) {
        $this->originalAssetUrl = $this->app['config']['app.asset_url'];
        $this->originalStoragePath = $app->storagePath();
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

        $path = $suffix
            ? $this->tenantStoragePath($suffix) . '/framework/cache'
            : $this->originalStoragePath . '/framework/cache';

        if (! is_dir($path)) {
            // Create tenant framework/cache directory if it does not exist.
            // We ignore errors due to TOCTOU race conditions, instead we check for success below.
            @mkdir($path, 0750, true);

            if (! is_dir($path)) {
                throw new Exception("Unable to create tenant storage directory [{$path}].");
            }
        }

        if ($suffix === false) {
            $this->app->useStoragePath($this->originalStoragePath);
        } else {
            $this->app->useStoragePath($this->tenantStoragePath($suffix));
        }
    }

    protected function tenantStoragePath(string $suffix): string
    {
        return $this->originalStoragePath . DIRECTORY_SEPARATOR . $suffix;
    }

    protected function assetHelper(string|false $suffix): void
    {
        if (! $this->app['config']['tenancy.filesystem.asset_helper_override']) {
            return;
        }

        if ($suffix === false) {
            $this->app['config']['app.asset_url'] = $this->originalAssetUrl;
            $this->app['url']->useAssetOrigin($this->originalAssetUrl);

            return;
        }

        if ($this->originalAssetUrl) {
            $this->app['config']['app.asset_url'] = $this->originalAssetUrl . "/$suffix";
            $this->app['url']->useAssetOrigin($this->app['config']['app.asset_url']);
        } else {
            $this->app['url']->useAssetOrigin($this->app['url']->route('stancl.tenancy.asset', ['path' => '']));
        }
    }

    protected function forgetDisks(): void
    {
        $tenantDisks = $this->app['config']['tenancy.filesystem.disks'];
        $scopedDisks = [];

        foreach ($this->app['config']['filesystems.disks'] as $name => $disk) {
            if (isset($disk['driver'], $disk['disk'])
                && $disk['driver'] === 'scoped'
                && in_array($disk['disk'], $tenantDisks, true)) {
                $scopedDisks[] = $name;
            }
        }

        Storage::forgetDisk(array_merge($tenantDisks, $scopedDisks));
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
                ? rtrim($originalRoot, '/\\') . '/' . $suffix
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

        $stores = $suffix !== false
            ? $this->app['config']['tenancy.cache.stores']
            : array_keys($this->originalCachePaths);

        foreach ($stores as $name) {
            $store = $this->app['config']["cache.stores.{$name}"];

            // Only file stores have a path to scope. Skip stores that don't exist (null) or use another driver.
            if ($store === null || $store['driver'] !== 'file') {
                continue;
            }

            if ($suffix !== false && ! isset($this->originalCachePaths[$name])) {
                $this->originalCachePaths[$name] = $store['path'];
                $this->originalCacheLockPaths[$name] = $store['lock_path'] ?? null;
            }

            $path = $suffix ? $this->tenantScopedPath($this->originalCachePaths[$name], $suffix) : $this->originalCachePaths[$name];

            // Unlike path, lock_path is optional -- if it's not set, FileStore::lock() falls back to path
            // itself (see `$this->lockDirectory ?? $this->directory` in FileStore). Leave it null here rather
            // than hardcoding it to $path ourselves, so a store that didn't configure a separate lock_path
            // doesn't end up with one.
            $lockPath = $this->originalCacheLockPaths[$name];
            if ($suffix && $lockPath !== null) {
                $lockPath = $this->tenantScopedPath($lockPath, $suffix);
            }

            $this->app['config']["cache.stores.{$name}.path"] = $path;
            $this->app['config']["cache.stores.{$name}.lock_path"] = $lockPath;

            /** @var \Illuminate\Cache\FileStore $store */
            $store = $this->app['cache']->store($name)->getStore();
            $store->setDirectory($path);
            $store->setLockDirectory($lockPath);
        }
    }

    /**
     * Scope a configured path (a cache store's path or lock_path, or the session path)
     * to the tenant identified by $suffix.
     */
    protected function tenantScopedPath(string $configuredPath, string $suffix): string
    {
        // Normalize the paths to use the separator of the current OS.
        $configuredPath = str_replace('/', DIRECTORY_SEPARATOR, $configuredPath);
        $storagePath = str_replace('/', DIRECTORY_SEPARATOR, $this->originalStoragePath);

        if (str_starts_with($configuredPath, $storagePath . DIRECTORY_SEPARATOR)) {
            // Swap the central storage path prefix for the tenant's.
            // For example, storage_path('framework/cache/data') becomes storage_path('tenant1/framework/cache/data').
            return str($configuredPath)
                ->after($storagePath . DIRECTORY_SEPARATOR)
                ->prepend($this->tenantStoragePath($suffix) . DIRECTORY_SEPARATOR)
                ->toString();
        }

        // Otherwise $configuredPath isn't necessarily storage_path()-based, so just append the
        // suffix as a subdirectory, e.g. '/var/cache/foo' becomes '/var/cache/foo/tenant1'.
        return rtrim($configuredPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $suffix;
    }

    public function scopeSessions(string|false $suffix): void
    {
        if (! $this->app['config']['tenancy.filesystem.scope_sessions']) {
            return;
        }

        $originalPath = $this->originalSessionPath ?? $this->app['config']['session.files'];
        $this->originalSessionPath = $originalPath;

        $path = $suffix
            ? $this->tenantScopedPath($originalPath, $suffix)
            : $originalPath;

        if (! is_dir($path)) {
            // Create tenant session directory if it does not exist.
            // We ignore errors due to TOCTOU race conditions, instead we check for success below.
            @mkdir($path, 0750, true);

            if (! is_dir($path)) {
                throw new Exception("Unable to create tenant session directory [{$path}].");
            }
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

    /** Get the central storage path from the bound singleton instance of this class. */
    public static function getBoundCentralStoragePath(): string
    {
        return app(static::class)->originalStoragePath;
    }
}
