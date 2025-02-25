<?php

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tests\TestCase;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

uses(TestCase::class)->in(__DIR__);

function withTenantDatabases(bool $migrate = false)
{
    Event::listen(TenantCreated::class, JobPipeline::make(
        $migrate
            ? [CreateDatabase::class, MigrateDatabase::class]
            : [CreateDatabase::class]
    )->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
}

function withInitializationEvents()
{
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
}

function pest(): TestCase
{
    return \Pest\TestSuite::getInstance()->test;
}
