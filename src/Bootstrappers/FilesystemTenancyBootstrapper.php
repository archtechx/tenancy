<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Foundation\Application;
use Illuminate\Routing\UrlGenerator;
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
            $this->app->useStoragePath($this->originalStoragePath . "/{$suffix}");
        }
    }

    protected function assetHelper(string|false $suffix): void
    {
        if (! $this->app['config']['tenancy.filesystem.asset_helper_tenancy']) {
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
                ->replace('%storage_path%', storage_path())
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
}
