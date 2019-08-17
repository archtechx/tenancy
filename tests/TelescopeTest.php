<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;

class TelescopeTest extends TestCase
{
    public $autoCreateTenant = false;
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        Artisan::call('telescope:install');
        Artisan::call('migrate');
    }
}
