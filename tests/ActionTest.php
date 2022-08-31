<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Actions\CreateStorageSymlinksAction;
use Stancl\Tenancy\Actions\RemoveStorageSymlinksAction;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;

beforeEach(function () {
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
});

test('create storage symlinks action works', function() {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
    ]);

    /** @var \Stancl\Tenancy\Database\Models\Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    $this->assertDirectoryDoesNotExist($publicPath = public_path("public-$tenantKey"));

    CreateStorageSymlinksAction::handle($tenant);

    $this->assertDirectoryExists($publicPath);
    $this->assertEquals(storage_path("app/public/"), readlink($publicPath));
});

test('remove storage symlinks action works', function() {
    config([
        'tenancy.bootstrappers' => [
            FilesystemTenancyBootstrapper::class,
        ],
        'tenancy.filesystem.suffix_base' => 'tenant-',
        'tenancy.filesystem.root_override.public' => '%storage_path%/app/public/',
        'tenancy.filesystem.url_override.public' => 'public-%tenant_id%'
    ]);

    /** @var \Stancl\Tenancy\Database\Models\Tenant $tenant */
    $tenant = Tenant::create();
    $tenantKey = $tenant->getTenantKey();

    tenancy()->initialize($tenant);

    CreateStorageSymlinksAction::handle($tenant);

    $this->assertDirectoryExists($publicPath = public_path("public-$tenantKey"));

    RemoveStorageSymlinksAction::handle($tenant);

    $this->assertDirectoryDoesNotExist($publicPath);
});
