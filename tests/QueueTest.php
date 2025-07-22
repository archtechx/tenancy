<?php

declare(strict_types=1);

use Illuminate\Bus\Queueable;
use Spatie\Valuestore\Valuestore;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Tests\Etc\User;
use Stancl\Tenancy\Tests\Etc\Tenant;
use Illuminate\Support\Facades\Event;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Events\TenancyEnded;
use Illuminate\Queue\InteractsWithQueue;
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
use Stancl\Tenancy\Bootstrappers\PersistentQueueTenancyBootstrapper;
use Stancl\Tenancy\Listeners\QueueableListener;
use function Stancl\Tenancy\Tests\pest;
use function Stancl\Tenancy\Tests\withTenantDatabases;

beforeEach(function () {
    config([
        'tenancy.bootstrappers' => [DatabaseTenancyBootstrapper::class],
        'queue.default' => 'redis',
    ]);

    Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
    Event::listen(TenancyEnded::class, RevertToCentralContext::class);

    createValueStore();
});

afterEach(function () {
    pest()->valuestore->flush();
});

dataset('queue_bootstrappers', [
    QueueTenancyBootstrapper::class,
    PersistentQueueTenancyBootstrapper::class,
]);

function withQueueBootstrapper(string $class) {
    config(['tenancy.bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        $class,
    ]]);

    $class::__constructStatic(app());
}

test('tenant id is passed to tenant queues', function (string $bootstrapper) {
    withQueueBootstrapper($bootstrapper);
    withTenantDatabases();

    config(['queue.default' => 'sync']);

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    Event::fake([JobProcessing::class, JobProcessed::class]);

    dispatch(new TestJob(pest()->valuestore));

    Event::assertDispatched(JobProcessing::class, function ($event) {
        return $event->job->payload()['tenant_id'] === tenant('id');
    });
})->with('queue_bootstrappers');

test('tenant id is not passed to central queues', function (string $bootstrapper) {
    withQueueBootstrapper($bootstrapper);
    withTenantDatabases();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    Event::fake();

    config(['queue.connections.central' => [
        'driver' => 'sync',
        'central' => true,
    ]]);

    dispatch(new TestJob(pest()->valuestore))->onConnection('central');

    Event::assertDispatched(JobProcessing::class, function ($event) {
        return ! isset($event->job->payload()['tenant_id']);
    });
})->with('queue_bootstrappers');

test('tenancy is initialized inside queues', function (bool $shouldEndTenancy, string $bootstrapper) {
    withQueueBootstrapper($bootstrapper);
    withTenantDatabases();
    withFailedJobs();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    withUsers();

    $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

    pest()->valuestore->put('userName', 'Bar');

    dispatch(new TestJob(pest()->valuestore, $user));

    expect(pest()->valuestore->has('tenant_id'))->toBeFalse();

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    pest()->artisan('queue:work --once');

    expect(! tenancy()->initialized)->toBe($shouldEndTenancy);

    expect(DB::connection('central')->table('failed_jobs')->count())->toBe(0);

    expect(pest()->valuestore->get('tenant_id'))->toBe('The current tenant id is: ' . $tenant->id);

    $tenant->run(function () use ($user) {
        expect($user->fresh()->name)->toBe('Bar');
    });
})->with([true, false])->with('queue_bootstrappers');

test('changing the shouldQueue static property in parent class affects child classes unless the property is redefined', function () {
    // Parent – $shouldQueue is true
    expect(app(ShouldQueueListener::class)->shouldQueue(new stdClass()))->toBeTrue();

    // Child – $shouldQueue is redefined and set to false
    expect(app(ShouldNotQueueListener::class)->shouldQueue(new stdClass()))->toBeFalse();

    // Child – inherits $shouldQueue from ShouldQueueListener (true)
    expect(app(InheritedQueueListener::class)->shouldQueue(new stdClass()))->toBeTrue();

    // Update $shouldQueue of InheritedQueueListener's parent to see if it affects the child
    ShouldQueueListener::$shouldQueue = false;

    // Parent's $shouldQueue changed to false
    expect(app(InheritedQueueListener::class)->shouldQueue(new stdClass()))->toBeFalse();

    ShouldQueueListener::$shouldQueue = true;

    // Parent's $shouldQueue changed back to true
    // Child's $shouldQueue is still false because it was redefined
    expect(app(ShouldNotQueueListener::class)->shouldQueue(new stdClass()))->toBeFalse();
});

test('tenancy is initialized when retrying jobs', function (bool $shouldEndTenancy, string $bootstrapper) {
    withQueueBootstrapper($bootstrapper);
    withFailedJobs();
    withTenantDatabases();

    $tenant = Tenant::create();

    tenancy()->initialize($tenant);

    withUsers();

    $user = User::create(['name' => 'Foo', 'email' => 'foo@bar.com', 'password' => 'secret']);

    pest()->valuestore->put('userName', 'Bar');
    pest()->valuestore->put('shouldFail', true);

    dispatch(new TestJob(pest()->valuestore, $user));

    expect(pest()->valuestore->has('tenant_id'))->toBeFalse();

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    pest()->artisan('queue:work --once');

    expect(! tenancy()->initialized)->toBe($shouldEndTenancy);

    expect(DB::connection('central')->table('failed_jobs')->count())->toBe(1);
    expect(pest()->valuestore->get('tenant_id'))->toBeNull(); // job failed

    pest()->artisan('queue:retry all');

    if ($shouldEndTenancy) {
        tenancy()->end();
    }

    pest()->artisan('queue:work --once');

    expect(! tenancy()->initialized)->toBe($shouldEndTenancy);

    expect(DB::connection('central')->table('failed_jobs')->count())->toBe(0);

    expect(pest()->valuestore->get('tenant_id'))->toBe('The current tenant id is: ' . $tenant->id); // job succeeded

    $tenant->run(function () use ($user) {
        expect($user->fresh()->name)->toBe('Bar');
    });
})->with([true, false])->with('queue_bootstrappers');

test('the tenant used by the job doesnt change when the current tenant changes', function (string $bootstrapper) {
    withQueueBootstrapper($bootstrapper);
    withTenantDatabases();

    $tenant1 = Tenant::create();

    tenancy()->initialize($tenant1);

    dispatch(new TestJob(pest()->valuestore));

    $tenant2 = Tenant::create();

    tenancy()->initialize($tenant2);

    expect(pest()->valuestore->has('tenant_id'))->toBeFalse();
    pest()->artisan('queue:work --once');

    expect(pest()->valuestore->get('tenant_id'))->toBe('The current tenant id is: ' . $tenant1->getTenantKey());
})->with('queue_bootstrappers');

// Regression test for #1277
test('dispatching a job from a tenant run arrow function dispatches it immediately', function (string $bootstrapper) {
    withQueueBootstrapper($bootstrapper);
    withTenantDatabases();

    $tenant = Tenant::create();

    $result = $tenant->run(fn () => dispatch(new TestJob(pest()->valuestore)));
    expect($result)->toBe(null);

    expect(tenant())->toBe(null);
    expect(pest()->valuestore->has('tenant_id'))->toBeFalse();
    pest()->artisan('queue:work --once');
    expect(tenant())->toBe(null);

    expect(pest()->valuestore->get('tenant_id'))->toBe('The current tenant id is: ' . $tenant->getTenantKey());
})->with('queue_bootstrappers');

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

    pest()->valuestore = Valuestore::make($valueStorePath)->flush();
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

class TestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var Valuestore */
    protected $valuestore;

    /** @var User|null */
    protected $user;

    public function __construct(Valuestore $valuestore, ?User $user = null)
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

class ShouldQueueListener extends QueueableListener
{
    public static bool $shouldQueue = true;

    public function handle()
    {
        return static::$shouldQueue;
    }
}

class ShouldNotQueueListener extends ShouldQueueListener
{
    public static bool $shouldQueue = false;

    public function handle()
    {
        return static::$shouldQueue;
    }
}

class InheritedQueueListener extends ShouldQueueListener
{
    public function handle()
    {
        return static::$shouldQueue;
    }
}
