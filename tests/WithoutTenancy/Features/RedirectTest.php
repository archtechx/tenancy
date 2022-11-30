<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Features\CrossDomainRedirect;
use Stancl\Tenancy\TenancyServiceProvider;
use Stancl\Tenancy\Tests\WithoutTenancy\TestCase;

uses(TestCase::class);

// Check that `domain()` can be called on a redirect before tenancy is used (regression test for #949)
test('redirect from central to tenant works', function (bool $enabled, bool $shouldThrow) {
    if ($enabled) {
        config()->set('tenancy.features', [CrossDomainRedirect::class]);
    }

    $this->app->register(new TenancyServiceProvider($this->app));

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
