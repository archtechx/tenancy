<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Artisan;

class CacheManagerTest extends TestCase
{
    public function setUp(): void
    {
        Artisan::call('telescope:install');
        Artisan::call('migrate');
    }
}
