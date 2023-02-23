<?php

use Stancl\Tenancy\Tests\TestCase;
use Stancl\JobPipeline\JobPipeline;
use Illuminate\Support\Facades\Event;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;

uses(TestCase::class)->in(...filesAndFoldersExcluding(['WithoutTenancy'])); // todo move all tests to a separate folder

function pest(): TestCase
{
    return Pest\TestSuite::getInstance()->test;
}

function filesAndFoldersExcluding(array $exclude = []): array
{
    $dirs = scandir(__DIR__);

    return array_filter($dirs, fn($dir) => ! in_array($dir, array_merge(['.', '..'], $exclude) , true));
}

function withTenantDatabases()
{
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
}
