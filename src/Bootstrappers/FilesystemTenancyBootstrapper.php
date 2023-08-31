<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class FilesystemTenancyBootstrapper implements TenancyBootstrapper
{
    /** @var Application */
    protected $app;

    /** @var array */
    public $originalPaths = [];

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->originalPaths = [
            'disks' => [],
            'storage' => $this->app->storagePath(),
            'asset_url' => $this->app['config']['app.asset_url'],
        ];

        $this->app['url']->macro('setAssetRoot', function ($root) {
            /** @var UrlGenerator $this */
            $this->assetRoot = $root;

            return $this;
        });
    }

    public function bootstrap(Tenant $tenant): void
    {
        $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . $tenant->getTenantKey();

        // storage_path()
        if ($this->app['config']['tenancy.filesystem.suffix_storage_path'] ?? true) {
            $this->app->useStoragePath($this->originalPaths['storage'] . "/{$suffix}");
        }

        // asset()
        if ($this->app['config']['tenancy.filesystem.asset_helper_tenancy']) {
            if ($this->originalPaths['asset_url']) {
                $this->app['config']['app.asset_url'] = $this->originalPaths['asset_url'] . "/$suffix";
                $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);
            } else {
                $this->app['url']->setAssetRoot($this->app['url']->route('stancl.tenancy.asset', ['path' => '']));
            }
        }

        // Storage facade
        Storage::forgetDisk($this->app['config']['tenancy.filesystem.disks']);

        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            // todo@v4 \League\Flysystem\PathPrefixer is making this a lot more painful in flysystem v2
            $diskConfig = $this->app['config']["filesystems.disks.{$disk}"];
            $originalRoot = $diskConfig['root'] ?? null;

            $this->originalPaths['disks']['path'][$disk] = $originalRoot;

            $finalPrefix = str_replace(
                ['%storage_path%', '%tenant%'],
                [storage_path(), $tenant->getTenantKey()],
                $this->app['config']["tenancy.filesystem.root_override.{$disk}"] ?? '',
            );

            if (! $finalPrefix) {
                $finalPrefix = $originalRoot
                    ? rtrim($originalRoot, '/') . '/' . $suffix
                    : $suffix;
            }

            $this->app['config']["filesystems.disks.{$disk}.root"] = $finalPrefix;

            // Storage Url
            if ($diskConfig['driver'] === 'local') {
                $this->originalPaths['disks']['url'][$disk] = $diskConfig['url'] ?? null;

                if ($url = str_replace(
                    '%tenant_id%',
                    (string) $tenant->getTenantKey(),
                    $this->app['config']["tenancy.filesystem.url_override.{$disk}"] ?? ''
                )) {
                    $this->app['config']["filesystems.disks.{$disk}.url"] = url($url);
                }
            }
        }
    }

    public function revert(): void
    {
        // storage_path()
        $this->app->useStoragePath($this->originalPaths['storage']);

        // asset()
        $this->app['config']['app.asset_url'] = $this->originalPaths['asset_url'];
        $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);

        // Storage facade
        Storage::forgetDisk($this->app['config']['tenancy.filesystem.disks']);
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $diskName) {
            $this->app['config']["filesystems.disks.$diskName.root"] = $this->originalPaths['disks']['path'][$diskName];
            $diskConfig = $this->app['config']['filesystems.disks.' . $diskName];

            // Storage Url
            $url = data_get($this->originalPaths, "disks.url.$diskName");

            if ($diskConfig['driver'] === 'local' && ! is_null($url)) {
                $this->app['config']["filesystems.disks.$diskName.url"] = $url;
            }
        }
    }
}
