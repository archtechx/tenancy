<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Route;
use Stancl\Tenancy\Tenant;

class RedirectTest extends TestCase
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

    /** @test */
    public function tenant_route_helper_generates_correct_url()
    {
        Route::get('/abcdef/{a?}/{b?}', function () {
            return 'Foo';
        })->name('foo');

        $this->assertSame('http://foo.localhost/abcdef/as/df', tenant_route('foo', ['a' => 'as', 'b' => 'df'], 'foo.localhost'));
        $this->assertSame('http://foo.localhost/abcdef', tenant_route('foo', [], 'foo.localhost'));

        $this->assertSame('http://' . request()->getHost() . '/abcdef/x/y', tenant_route('foo', ['a' => 'x', 'b' => 'y']));
    }
}
