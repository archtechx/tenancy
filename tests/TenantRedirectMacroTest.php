<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Route;

class TenantRedirectMacroTest extends TestCase
{
    /** @test */
    public function tenant_redirect_macro_replaces_only_the_hostname()
    {
        Route::get('/foobar', function () {
            return 'Foo';
        })->name('home');

        Route::get('/redirect', function () {
            return redirect()->route('home')->tenant('abcd');
        });

        $this->get('/redirect')
            ->assertRedirect('http://abcd/foobar');
    }
}
