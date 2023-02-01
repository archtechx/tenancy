<?php

use Stancl\Tenancy\Tests\TestCase;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;

uses(TestCase::class)->in(__DIR__);

function pest(): TestCase
{
    return Pest\TestSuite::getInstance()->test;
}

function withTenantDatabases()
{
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
}
