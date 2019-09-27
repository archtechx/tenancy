<?php

declare(strict_types=1);

namespace Stancl\Tenancy\TenancyBootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Tenant;

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

    public function start(Tenant $tenant)
    {
        $suffix = $this->app['config']['tenancy.filesystem.suffix_base'] . $tenant->id;

        // storage_path()
        $this->app->useStoragePath($this->originalPaths['storage'] . "/{$suffix}");

        // asset()
        if ($this->originalPaths['asset_url']) {
            $this->app['config']['app.asset_url'] = ($this->originalPaths['asset_url'] ?? $this->app['config']['app.url']) . "/$suffix";
            $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);
        } else {
            $this->app['url']->setAssetRoot($this->app['url']->route('stancl.tenancy.asset', ['path' => '']));
        }

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            $this->originalPaths['disks'][$disk] = Storage::disk($disk)->getAdapter()->getPathPrefix();

            if ($root = str_replace('%storage_path%', storage_path(), $this->app['config']["tenancy.filesystem.root_override.{$disk}"])) {
                Storage::disk($disk)->getAdapter()->setPathPrefix($root);
            } else {
                $root = $this->app['config']["filesystems.disks.{$disk}.root"];

                Storage::disk($disk)->getAdapter()->setPathPrefix($root . "/{$suffix}");
            }
        }
    }

    public function end()
    {
        // storage_path()
        $this->app->useStoragePath($this->originalPaths['storage']);

        // asset()
        $this->app['config']['app.asset_url'] = $this->originalPaths['asset_url'];
        $this->app['url']->setAssetRoot($this->app['config']['app.asset_url']);

        // Storage facade
        foreach ($this->app['config']['tenancy.filesystem.disks'] as $disk) {
            Storage::disk($disk)->getAdapter()->setPathPrefix($this->originalPaths['disks'][$disk]);
        }
    }
}
