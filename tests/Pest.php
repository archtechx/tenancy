<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Database\Schema\Blueprint;
use Stancl\Tenancy\Tests\TestCase;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Jobs\MigrateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

uses(TestCase::class)->in(__DIR__);

function withBootstrapping()
{
    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);
}

function withTenantDatabases(bool $migrate = false)
{
    Event::listen(TenantCreated::class, JobPipeline::make($migrate
        ? [CreateDatabase::class, MigrateDatabase::class]
        : [CreateDatabase::class]
    )->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
}

function withCacheTables()
{
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    Schema::create('cache_locks', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->string('owner');
        $table->integer('expiration');
    });
}

function pest(): TestCase
{
    return \Pest\TestSuite::getInstance()->test;
}
