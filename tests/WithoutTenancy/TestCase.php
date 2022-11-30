<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\WithoutTenancy;

abstract class TestCase extends \Stancl\Tenancy\Tests\TestCase
{
    protected function getPackageProviders($app)
    {
        return []; // We will provide TenancyServiceProvider in tests
    }
}
