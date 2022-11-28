<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Features\CrossDomainRedirect;
use Stancl\Tenancy\Tests\Etc\Tenant;

test('tenant redirect macro replaces only the hostname', function () {
    config([
        'tenancy.tenant_unaware_features' => [CrossDomainRedirect::class],
    ]);

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
test('redirect from central to tenant works', function () {
    // `CrossDomainRedirect` feature already enabled in config

    Route::get('/foobar', function () {
        return 'Foo';
    })->name('home');

    Route::get('/redirect', function () {
        return redirect()->route('home')->domain('abcd');
    });

    pest()->get('/redirect')
        ->assertRedirect('http://abcd/foobar');
});
