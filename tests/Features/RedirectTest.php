<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Features;

use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Features\CrossDomainRedirect;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Tests\TestCase;

class RedirectTest extends TestCase
{
    /** @test */
    public function tenant_redirect_macro_replaces_only_the_hostname()
    {
        config([
            'tenancy.features' => [CrossDomainRedirect::class],
        ]);

        Route::get('/foobar', function () {
            return 'Foo';
        })->name('home');

        Route::get('/redirect', function () {
            return redirect()->route('home')->domain('abcd');
        });

        $tenant = Tenant::create();
        tenancy()->initialize($tenant);

        $this->get('/redirect')
            ->assertRedirect('http://abcd/foobar');
    }

    /** @test */
    public function tenant_route_helper_generates_correct_url()
    {
        Route::get('/abcdef/{a?}/{b?}', function () {
            return 'Foo';
        })->name('foo');

        $this->assertSame('http://foo.localhost/abcdef/as/df', tenant_route('foo.localhost', 'foo', ['a' => 'as', 'b' => 'df']));
        $this->assertSame('http://foo.localhost/abcdef', tenant_route('foo.localhost', 'foo', []));
    }
}
