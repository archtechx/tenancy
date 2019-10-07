<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Route;
use Stancl\Tenancy\Tenant;

class TenantRedirectMacroTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    /** @test */
    public function tenant_redirect_macro_replaces_only_the_hostname()
    {
        config([
            'tenancy.features' => ['Stancl\Tenancy\Features\TenantRedirect'],
        ]);

        Route::get('/foobar', function () {
            return 'Foo';
        })->name('home');

        Route::get('/redirect', function () {
            return redirect()->route('home')->tenant('abcd');
        });

        Tenant::create('foo.localhost');
        tenancy()->init('foo.localhost');

        $this->get('/redirect')
            ->assertRedirect('http://abcd/foobar');
    }
}
