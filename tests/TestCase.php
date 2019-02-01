<?php

namespace Stancl\Tenancy\Tests;

class TestCase extends \Orchestra\Testbench\TestCase
{
    /**
     * Setup the test environment
     *
     * @return void
     */
    protected function setUp()
    {
        parent::setUp();

        //
    }

    protected function getPackageProviders($app)
    {
        return [\Stancl\Tenancy\TenancyServiceProvider::class];
    }

    /**
     * Resolve application HTTP Kernel implementation.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return void
     */
    protected function resolveApplicationHttpKernel($app)
    {
        $app->singleton('Illuminate\Contracts\Http\Kernel', \Stancl\Tenancy\Testing\HttpKernel::class);
    }
}
