<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

class QueueTest extends TestCase
{
    /** @test */
    public function queues_use_non_tenant_db_connection()
    {
        // requires using the db driver
        $this->markTestIncomplete();
    }

    /** @test */
    public function tenancy_is_initialized_inside_queues()
    {
        $this->loadLaravelMigrations(['--database' => 'tenant']);
        Event::fake();

        dispatch(new TestJob());

        Event::assertDispatched(JobProcessing::class, function ($event) {
            return $event->job->payload()['tenant_id'] === tenant('id');
        });
    }

    /** @test */
    public function tenancy_is_not_initialized_in_non_tenant_queues()
    {
        $this->loadLaravelMigrations(['--database' => 'tenant']);
        Event::fake();

        dispatch(new TestJob())->onConnection('central');

        Event::assertDispatched(JobProcessing::class, function ($event) {
            return ! isset($event->job->payload()['tenant_id']);
        });
    }
}

class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        logger(json_encode(\DB::table('users')->get()));
    }
}
