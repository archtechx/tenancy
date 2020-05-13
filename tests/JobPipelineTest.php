<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spatie\Valuestore\Valuestore;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Listeners\JobPipeline;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Tests\TestCase;

class JobPipelineTest extends TestCase
{
    public $mockConsoleOutput = false;

    /** @var Valuestore */
    protected $valuestore;

    public function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'redis']);

        $this->valuestore = Valuestore::make(__DIR__ . '/Etc/tmp/jobpipelinetest.json')->flush();
    }

    /** @test */
    public function job_pipeline_can_listen_to_any_event()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->toListener());

        $this->assertFalse($this->valuestore->has('foo'));

        Tenant::create();

        $this->assertSame('bar', $this->valuestore->get('foo'));
    }

    /** @test */
    public function job_pipeline_can_be_queued()
    {
        Queue::fake();

        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->shouldBeQueued(true)->toListener());

        Queue::assertNothingPushed();

        Tenant::create();
        $this->assertFalse($this->valuestore->has('foo'));

        Queue::pushed(JobPipeline::class, function (JobPipeline $pipeline) {
            $this->assertSame([FooJob::class], $pipeline->jobs);
        });
    }

    /** @test */
    public function job_pipelines_run_when_queued()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FooJob::class,
        ])->send(function () {
            return $this->valuestore;
        })->shouldBeQueued(true)->toListener());

        $this->assertFalse($this->valuestore->has('foo'));
        Tenant::create();
        $this->artisan('queue:work --once');

        $this->assertSame('bar', $this->valuestore->get('foo'));
    }

    /** @test */
    public function job_pipeline_executes_jobs_and_passes_the_object_sequentially()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            FirstJob::class,
            SecondJob::class,
        ])->send(function (TenantCreated $event) {
            return [$event->tenant, $this->valuestore];
        })->toListener());

        $this->assertFalse($this->valuestore->has('foo'));

        Tenant::create();

        $this->assertSame('first job changed property', $this->valuestore->get('foo'));
    }

    /** @test */
    public function send_can_return_multiple_arguments()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([
            JobWithMultipleArguments::class
        ])->send(function () {
            return ['a', 'b'];
        })->toListener());

        $this->assertFalse(app()->bound('test_args'));

        Tenant::create();

        $this->assertSame(['a', 'b'], app('test_args'));
    }
}

class FooJob
{
    protected $valuestore;

    public function __construct(Valuestore $valuestore)
    {
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        $this->valuestore->put('foo', 'bar');
    }
};

class FirstJob
{
    public $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle()
    {
        $this->tenant->foo = 'first job changed property';
    }
}

class SecondJob
{
    public $tenant;

    protected $valuestore;

    public function __construct(Tenant $tenant, Valuestore $valuestore)
    {
        $this->tenant = $tenant;
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        $this->valuestore->put('foo', $this->tenant->foo);
    }
}

class JobWithMultipleArguments
{
    protected $first;
    protected $second;

    public function __construct($first, $second)
    {
        $this->first = $first;
        $this->second = $second;
    }

    public function handle()
    {
        // we dont queue this job so no need to use valuestore here
        app()->instance('test_args', [$this->first, $this->second]);
    }
}
