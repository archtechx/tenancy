<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemAdapter;
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
            $this->assetRoot = $root;

            return $this;
        });
    }

    public function bootstrap(Tenant $tenant)
    {
        $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . $tenant->getTenantKey();

        // storage_path()
        if ($this->app['config']['tenancy.filesystem.suffix_storage_path'] ?? true) {
            $this->app->useStoragePath($this->originalPaths['storage'] . "/{$suffix}");
        }

        // asset()
        if ($this->app['config']['tenancy.filesystem.asset_helper_tenancy'] ?? true) {
            if ($this->originalPaths['asset_url']) {
                $this->app['config']['app.asset_url'] = ($this->originalPaths['asset_url'] ?? $this->app['config']['app.url']) . "/$suffix";
                $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);
            } else {
                $this->app['url']->setAssetRoot($this->app['url']->route('stancl.tenancy.asset', ['path' => '']));
            }
        }

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            /** @var FilesystemAdapter $filesystemDisk */
            $filesystemDisk = Storage::disk($disk);
            $this->originalPaths['disks'][$disk] = $filesystemDisk->getAdapter()->getPathPrefix();

            if ($root = str_replace(
                '%storage_path%',
                storage_path(),
                $this->app['config']["tenancy.filesystem.root_override.{$disk}"] ?? ''
            )) {
                $filesystemDisk->getAdapter()->setPathPrefix($finalPrefix = $root);
            } else {
                $root = $this->app['config']["filesystems.disks.{$disk}.root"];
                $filesystemDisk->getAdapter()->setPathPrefix($finalPrefix = $root . "/{$suffix}");
            }

            $this->app['config']["filesystems.disks.{$disk}.root"] = $finalPrefix;
        }
    }

    public function revert()
    {
        // storage_path()
        $this->app->useStoragePath($this->originalPaths['storage']);

        // asset()
        $this->app['config']['app.asset_url'] = $this->originalPaths['asset_url'];
        $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            /** @var FilesystemAdapter $filesystemDisk */
            $filesystemDisk = Storage::disk($disk);

            $root = $this->originalPaths['disks'][$disk];

            $filesystemDisk->getAdapter()->setPathPrefix($root);
            $this->app['config']["filesystems.disks.{$disk}.root"] = $root;
        }
    }
}
