<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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

    Storage::disk('public')->put('test.txt', 'test');

    $this->assertDirectoryDoesNotExist(public_path("public-$tenantKey"));

    CreateStorageSymlinksAction::handle($tenant);

    $this->assertDirectoryExists(storage_path("app/public"));
    $this->assertDirectoryExists(public_path("public-$tenantKey"));
    $this->assertEquals(storage_path("app/public/"), readlink(public_path("public-$tenantKey")));
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

    RemoveStorageSymlinksAction::handle($tenant);

    $this->assertDirectoryDoesNotExist(public_path("public-$tenantKey"));
});
