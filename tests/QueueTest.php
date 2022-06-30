<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Spatie\Valuestore\Valuestore;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\User;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Events\TenantCreated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [
            QueueTenancyBootstrapper::class,
            DatabaseTenancyBootstrapper::class,
        ],
        'queue.default' => 'redis',
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    createValueStore();
});

afterEach(function () {
    $this->valuestore->flush();
});

test('tenant id is passed to tenant queues', function () {
    config(['queue.default' => 'sync']);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    Event::fake([JobProcessing::class, JobProcessed::class]);

    dispatch(new TestJob($this->valuestore));

    Event::assertDispatched(JobProcessing::class, function ($event) {
        return $event->job->payload()['tenant_id'] === tenant('id');
    });
});

test('tenant id is not passed to central queues', function () {
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
});

test('tenancy is initialized inside queues', function (bool $shouldEndTenancy) {
    withTenantDatabases();
    withFailedJobs();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    withUsers();

    $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

    $this->valuestore->put('userName', 'Bar');

    dispatch(new TestJob($this->valuestore, $user));

    expect($this->valuestore->has('tenant_id'))->toBeFalse();

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    $this->artisan('queue:work --once');

    expect(DB::connection('central')->table('failed_jobs')->count())->toBe(0);

    expect($this->valuestore->get('tenant_id'))->toBe('The current tenant id is: ' . $tenant->id);

    $tenant->run(function () use ($user) {
        expect($user->fresh()->name)->toBe('Bar');
    });
})->with([true, false]);;

test('tenancy is initialized when retrying jobs', function (bool $shouldEndTenancy) {
    if (! Str::startsWith(app()->version(), '8')) {
        $this->markTestSkipped('queue:retry tenancy is only supported in Laravel 8');
    }

    withFailedJobs();
    withTenantDatabases();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    withUsers();

    $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

    $this->valuestore->put('userName', 'Bar');
    $this->valuestore->put('shouldFail', true);

    dispatch(new TestJob($this->valuestore, $user));

    expect($this->valuestore->has('tenant_id'))->toBeFalse();

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    $this->artisan('queue:work --once');

    expect(DB::connection('central')->table('failed_jobs')->count())->toBe(1);
    expect($this->valuestore->get('tenant_id'))->toBeNull(); // job failed

    $this->artisan('queue:retry all');
    $this->artisan('queue:work --once');

    expect(DB::connection('central')->table('failed_jobs')->count())->toBe(0);

    expect($this->valuestore->get('tenant_id'))->toBe('The current tenant id is: ' . $tenant->id); // job succeeded

    $tenant->run(function () use ($user) {
        expect($user->fresh()->name)->toBe('Bar');
    });
})->with([true, false]);

test('the tenant used by the job doesnt change when the current tenant changes', function () {
    $tenant1 = Tenant::create([
        'id' => 'acme',
    ]);

    tenancy()->initialize($tenant1);

    dispatch(new TestJob($this->valuestore));

    $tenant2 = Tenant::create([
        'id' => 'foobar',
    ]);

    tenancy()->initialize($tenant2);

    expect($this->valuestore->has('tenant_id'))->toBeFalse();
    $this->artisan('queue:work --once');

    expect($this->valuestore->get('tenant_id'))->toBe('The current tenant id is: acme');
});

function createValueStore(): void
{
    $valueStorePath = __DIR__ . '/Etc/tmp/queuetest.json';

    if (! file_exists($valueStorePath)) {
        // The directory sometimes goes missing as well when the file is deleted in git
        if (! is_dir(__DIR__ . '/Etc/tmp')) {
            mkdir(__DIR__ . '/Etc/tmp');
        }

        file_put_contents($valueStorePath, '');
    }

    test()->valuestore = Valuestore::make($valueStorePath)->flush();
}

function withFailedJobs()
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

function withUsers()
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

function withTenantDatabases()
{
    Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
        return $event->tenant;
    })->toListener());
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

