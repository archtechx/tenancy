<?php

use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Tests\Etc\Tenant as TenantModel;

test('only bootstrappers that have been initialized are reverted', function () {
    config(['tenancy.bootstrappers' => [
        Initialized_DummyBootstrapperFoo::class,
        Initialized_DummyBootstrapperBar::class,
        Initialized_DummyBootstrapperBaz::class,
    ]]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    // Only needs to be done in tests
    app()->singleton(Initialized_DummyBootstrapperFoo::class);
    app()->singleton(Initialized_DummyBootstrapperBar::class);
    app()->singleton(Initialized_DummyBootstrapperBaz::class);

    $tenant = TenantModel::create();

    try {
        $tenant->run(fn() => null);
        // Should throw an exception
        expect(true)->toBe(false);
    } catch (Exception $e) {
        // NOT 'baz fail in revert' as was the behavior before
        // the commit that added this test
        expect($e->getMessage())->toBe('bar fail in bootstrap');
    }

    expect(tenancy()->initializedBootstrappers)->toBe([
        Initialized_DummyBootstrapperFoo::class,
    ]);
});

class Initialized_DummyBootstrapperFoo implements TenancyBootstrapper
{
    public string $bootstrapped = 'uninitialized';

    public function bootstrap(Tenant $tenant): void
    {
        $this->bootstrapped = 'bootstrapped';
    }

    public function revert(): void
    {
        $this->bootstrapped = 'reverted';
    }
}

class Initialized_DummyBootstrapperBar implements TenancyBootstrapper
{
    public string $bootstrapped = 'uninitialized';

    public function bootstrap(Tenant $tenant): void
    {
        throw new Exception('bar fail in bootstrap');
    }

    public function revert(): void
    {
        $this->bootstrapped = 'reverted';
    }
}

class Initialized_DummyBootstrapperBaz implements TenancyBootstrapper
{
    public string $bootstrapped = 'uninitialized';

    public function bootstrap(Tenant $tenant): void
    {
        $this->bootstrapped = 'bootstrapped';
    }

    public function revert(): void
    {
        throw new Exception('baz fail in revert');
    }
}
