<?php

declare(strict_types=1);

use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\TenancyServiceProvider;
use Stancl\Tenancy\Features\CrossDomainRedirect;

beforeAll(function () {
    TenancyServiceProvider::$bootstrapFeatures = false;
});

test('tenant redirect macro replaces only the hostname', function () {
    config()->set('tenancy.features', [CrossDomainRedirect::class]);

    TenancyServiceProvider::bootstrapFeatures();

    Route::get('/foobar', function () {
        return 'Foo';
    })->name('home');

    Route::get('/redirect', function () {
        return redirect()->route('home')->domain('abcd');
    });

    $tenant = Tenant::create();
    tenancy()->initialize($tenant);

    pest()->get('/redirect')
        ->assertRedirect('http://abcd/foobar');
});

test('tenant route helper generates correct url', function () {
    Route::get('/abcdef/{a?}/{b?}', function () {
        return 'Foo';
    })->name('foo');

    expect(tenant_route('foo.localhost', 'foo', ['a' => 'as', 'b' => 'df']))->toBe('http://foo.localhost/abcdef/as/df');
    expect(tenant_route('foo.localhost', 'foo', []))->toBe('http://foo.localhost/abcdef');
});

// Check that `domain()` can be called on a redirect before tenancy is used (regression test for #949)
test('redirect from central to tenant works', function (bool $enabled, bool $shouldThrow) {
    if ($enabled) {
        config()->set('tenancy.features', [CrossDomainRedirect::class]);
    }

    TenancyServiceProvider::bootstrapFeatures();

    Route::get('/foobar', function () {
        return 'Foo';
    })->name('home');

    Route::get('/redirect', function () {
        return redirect()->route('home')->domain('abcd');
    });

    try {
        pest()->get('/redirect')
            ->assertRedirect('http://abcd/foobar');

        if ($shouldThrow) {
            pest()->fail('Exception not thrown');
        }
    } catch (Throwable $e) {
        if ($shouldThrow) {
            pest()->assertTrue(true); // empty assertion to make the test pass
        } else {
            pest()->fail('Exception thrown: ' . $e->getMessage());
        }
    }

})->with([
    ['enabled' => false, 'shouldThrow' => true],
    ['enabled' => true, 'shouldThrow' => false],
]);
