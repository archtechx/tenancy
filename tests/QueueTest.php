<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Exception;
use Illuminate\Bus\Queueable;
use Spatie\Valuestore\Valuestore;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\User;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Illuminate\Queue\InteractsWithQueue;
use Stancl\Tenancy\Events\TenantCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

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
                DatabaseTenancyBootstrapper::class,
            ],
            'queue.default' => 'redis',
        ]);

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        $this->createValueStore();
    }

    public function tearDown(): void
    {
        parent::tearDown();
        $this->valuestore->flush();
    }

    protected function createValueStore(): void
    {
        $valueStorePath = __DIR__ . '/Etc/tmp/queuetest.json';

        if (! file_exists($valueStorePath)) {
            // The directory sometimes goes missing as well when the file is deleted in git
            if (! is_dir(__DIR__ . '/Etc/tmp')) {
                mkdir(__DIR__ . '/Etc/tmp');
            }

            file_put_contents($valueStorePath, '');
        }

        $this->valuestore = Valuestore::make($valueStorePath)->flush();
    }

    protected function withFailedJobs()
    {
        Schema::connection('central')->create('failed_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    protected function withUsers()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    protected function withTenantDatabases()
    {
        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());
    }

    /** @test */
    public function tenant_id_is_passed_to_tenant_queues()
    {
        config(['queue.default' => 'sync']);

        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        Event::fake([JobProcessing::class, JobProcessed::class]);

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

    /**
     * @test
     *
     * @testWith [true]
     *           [false]
     */
    public function tenancy_is_initialized_inside_queues(bool $shouldEndTenancy)
    {
        $this->withTenantDatabases();
        $this->withFailedJobs();

        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        $this->withUsers();

        $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

        $this->valuestore->put('userName', 'Bar');

        dispatch(new TestJob($this->valuestore, $user));

        $this->assertFalse($this->valuestore->has('tenant_id'));

        if ($shouldEndTenancy) {
            tenancy()->end();
        }

        $this->artisan('queue:work --once');

        $this->assertSame(0, DB::connection('central')->table('failed_jobs')->count());

        $this->assertSame('The current tenant id is: ' . $tenant->id, $this->valuestore->get('tenant_id'));

        $tenant->run(function () use ($user) {
            $this->assertSame('Bar', $user->fresh()->name);
        });
    }

    /**
     * @test
     *
     * @testWith [true]
     *           [false]
     */
    public function tenancy_is_initialized_when_retrying_jobs(bool $shouldEndTenancy)
    {
        $this->withFailedJobs();
        $this->withTenantDatabases();

        $tenant = Tenant::create();

        tenancy()->initialize($tenant);

        $this->withUsers();

        $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

        $this->valuestore->put('userName', 'Bar');
        $this->valuestore->put('shouldFail', true);

        dispatch(new TestJob($this->valuestore, $user));

        $this->assertFalse($this->valuestore->has('tenant_id'));

        if ($shouldEndTenancy) {
            tenancy()->end();
        }

        $this->artisan('queue:work --once');

        $this->assertSame(1, DB::connection('central')->table('failed_jobs')->count());
        $this->assertNull($this->valuestore->get('tenant_id')); // job failed

        $this->artisan('queue:retry all');
        $this->artisan('queue:work --once');

        $this->assertSame(0, DB::connection('central')->table('failed_jobs')->count());

        $this->assertSame('The current tenant id is: ' . $tenant->id, $this->valuestore->get('tenant_id')); // job succeeded

        $tenant->run(function () use ($user) {
            $this->assertSame('Bar', $user->fresh()->name);
        });
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

    /** @var User|null */
    protected $user;

    public function __construct(Valuestore $valuestore, User $user = null)
    {
        $this->valuestore = $valuestore;
        $this->user = $user;
    }

    public function handle()
    {
        if ($this->valuestore->get('shouldFail')) {
            $this->valuestore->put('shouldFail', false);

            throw new Exception('failing');
        }

        if ($this->user) {
            assert($this->user->getConnectionName() === 'tenant');
        }

        $this->valuestore->put('tenant_id', 'The current tenant id is: ' . tenant('id'));

        if ($userName = $this->valuestore->get('userName')) {
            $this->user->update(['name' => $userName]);
        }
    }
}
