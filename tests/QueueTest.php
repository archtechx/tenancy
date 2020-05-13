<?php

namespace Stancl\Tenancy\Tests;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;
use Spatie\Valuestore\Valuestore;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Tests\TestCase;

class QueueTest extends TestCase
{
    public $mockConsoleOutput = false;

    /** @var Valuestore */
    protected $valuestore;

    public function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.bootstrappers' => [
                QueueTenancyBootstrapper::class,
            ],
            'queue.default' => 'redis',
        ]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);

        $this->valuestore = Valuestore::make(__DIR__ . '/Etc/tmp/queuetest.json')->flush();
    }

    /** @test */
    public function tenant_id_is_passed_to_tenant_queues()
    {
        config(['queue.default' => 'sync']);

        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        Event::fake([JobProcessing::class]);

        dispatch(new TestJob($this->valuestore));

        Event::assertDispatched(JobProcessing::class, function ($event) {
            return $event->job->payload()['tenant_id'] === tenant('id');
        });
    }

    /** @test */
    public function tenant_id_is_not_passed_to_central_queues()
    {
        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        Event::fake();

        config(['queue.connections.central' => [
            'driver' => 'sync',
            'central' => true,
        ]]);

        dispatch(new TestJob($this->valuestore))->onConnection('central');

        Event::assertDispatched(JobProcessing::class, function ($event) {
            return ! isset($event->job->payload()['tenant_id']);
        });
    }

    /** @test */
    public function tenancy_is_initialized_inside_queues()
    {
        $tenant = Tenant::create([
            'id' => 'acme',
        ]);

        tenancy()->initialize($tenant);

        dispatch(new TestJob($this->valuestore));

        $this->assertFalse($this->valuestore->has('tenant_id'));
        $this->artisan('queue:work --once');

        $this->assertSame('The current tenant id is: acme', $this->valuestore->get('tenant_id'));
    }

    /** @test */
    public function the_tenant_used_by_the_job_doesnt_change_when_the_current_tenant_changes()
    {
        $tenant1 = Tenant::create([
            'id' => 'acme',
        ]);

        tenancy()->initialize($tenant1);

        dispatch(new TestJob($this->valuestore));

        $tenant2 = Tenant::create([
            'id' => 'foobar',
        ]);

        tenancy()->initialize($tenant2);

        $this->assertFalse($this->valuestore->has('tenant_id'));
        $this->artisan('queue:work --once');

        $this->assertSame('The current tenant id is: acme', $this->valuestore->get('tenant_id'));
    }
}

class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var Valuestore */
    protected $valuestore;

    public function __construct(Valuestore $valuestore)
    {
        $this->valuestore = $valuestore;
    }

    public function handle()
    {
        $this->valuestore->put('tenant_id', "The current tenant id is: " . tenant('id'));
    }
}
