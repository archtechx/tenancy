<?php

namespace Stancl\Tenancy\Tests\v3;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Events\Listeners\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Tests\TestCase;

// todo the shouldQueue() doesnt make sense? test if it really works or if its just because of sync queue driver
class JobPipelineTest extends TestCase
{
    /** @test */
    public function job_pipeline_can_listen_to_any_event()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->toListener());

        $this->assertFalse(app()->bound('foo'));

        Tenant::create();

        $this->assertSame('bar', app('foo'));
    }

    /** @test */
    public function job_pipeline_can_be_queued()
    {
        Queue::fake();

        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->shouldQueue(true)->toListener());

        Queue::assertNothingPushed();

        Tenant::create();
        $this->assertFalse(app()->bound('foo'));

        Queue::pushed(JobPipeline::class, function (JobPipeline $pipeline) {
            $this->assertSame([FooJob::class], $pipeline->jobs);
        });
    }

    /** @test */
    public function job_pipeline_executes_jobs_and_passes_the_object_sequentially()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FirstJob::class,
            SecondJob::class,
        ])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        $this->assertFalse(app()->bound('foo'));

        Tenant::create();

        $this->assertSame('first job changed property', app('foo'));
    }

    /** @test */
    public function send_can_return_multiple_arguments()
    {
        // todo
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
