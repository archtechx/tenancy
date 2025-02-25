<?php

namespace Stancl\Tenancy\Tests;

use Stancl\Tenancy\Tests\TestCase;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;

uses(TestCase::class)->in(__DIR__);

function withTenantDatabases()
{
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
}

function pest(): TestCase
{
    return \Pest\TestSuite::getInstance()->test;
}
