<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class QueueTest extends TestCase
{
    /** @test */
    public function queues_use_non_tenant_db_connection()
    {
        // todo finish this test. requires using the db driver
        $this->markTestIncomplete();
    }

    /** @test */
    public function tenancy_is_initialized_inside_queues()
    {
        $this->loadLaravelMigrations(['--database' => 'tenant']);
        Event::fake();

        dispatch(new TestJob());

        Event::assertDispatched(JobProcessing::class, function ($event) {
            return $event->job->payload()['tenant_uuid'] === tenant('uuid');
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
        logger(\json_encode(\DB::table('users')->get()));
    }
}
