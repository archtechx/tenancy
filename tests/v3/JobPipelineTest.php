<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\Listeners\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Tests\TestCase;

class JobPipelineTest extends TestCase
{
    /** @test */
    public function job_pipeline_can_listen_to_any_event()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->toClosure());

        $this->assertFalse(app()->bound('foo'));
        
        Tenant::create();

        $this->assertSame('bar', app('foo'));
    }

    /** @test */
    public function job_pipeline_can_be_queued()
    {
        // todo: This does not work because of toClosure

        Queue::fake();

        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->queue(true)->toClosure());

        Queue::assertNothingPushed();

        Tenant::create();
        $this->assertFalse(app()->bound('foo'));

        Queue::assertPushed(JobPipeline::class);
    }

    /** @test */
    public function job_pipeline_executes_jobs_and_passes_the_object_sequentially()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FirstJob::class,
            SecondJob::class,
        ])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toClosure());

        $this->assertFalse(app()->bound('foo'));

        // todo: for some reason, SecondJob is not reached in the pipeline
        Tenant::create();

        $this->assertSame('first job changed property', app('foo'));
    }
}

class FooJob
{
    public function handle()
    {
        app()->instance('foo', 'bar');
    }
};

class FirstJob
{
    public function handle(Tenant $tenant)
    {
        $tenant->foo = 'first job changed property';
    }
}

class SecondJob
{
    public function handle(Tenant $tenant)
    {
        app()->instance('foo', $tenant->foo);
    }
}
