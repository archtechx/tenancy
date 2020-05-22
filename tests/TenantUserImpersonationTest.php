<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Carbon\Carbon;
use Carbon\CarbonInterval;
use Closure;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Auth\User as Authenticable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Features\UserImpersonation;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\InitializeTenancyByPath;
use Stancl\Tenancy\Tests\Etc\Tenant;

class TenantUserImpersonationTest extends TestCase
{
    protected function migrateTenants()
    {
        $this->artisan('tenants:migrate')->assertExitCode(0);
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', [
            '--path' => __DIR__ . '/../assets/impersonation-migrations',
            '--realpath' => true,
        ])->assertExitCode(0);

        config([
            'tenancy.bootstrappers' => [
                DatabaseTenancyBootstrapper::class,
            ],
            'tenancy.features' => [
                UserImpersonation::class,
            ],
        ]);

        Event::listen(
            TenantCreated::class,
            JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
                return $event->tenant;
            })->toListener()
        );

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        config(['auth.providers.users.model' => ImpersonationUser::class]);
    }

    public function makeLoginRoute()
    {
        Route::get('/login', function () {
            return 'Please log in';
        })->name('login');
    }

    public function getRoutes($loginRoute = true, $authGuard = 'web'): Closure
    {
        return function () use ($loginRoute, $authGuard) {
            if ($loginRoute) {
                $this->makeLoginRoute();
            }

            Route::get('/dashboard', function () use ($authGuard) {
                return 'You are logged in as ' . auth()->guard($authGuard)->user()->name;
            })->middleware('auth:' . $authGuard);

            Route::get('/impersonate/{token}', function ($token) {
                return UserImpersonation::makeResponse($token);
            });
        };
    }

    /** @test */
    public function tenant_user_can_be_impersonated_on_a_tenant_domain()
    {
        Route::middleware(InitializeTenancyByDomain::class)->group($this->getRoutes());

        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);
        $this->migrateTenants();
        $user = $tenant->run(function () {
            return ImpersonationUser::create([
                'name' => 'Joe',
                'email' => 'joe@local',
                'password' => bcrypt('secret'),
            ]);
        });

        // We try to visit the dashboard directly, before impersonating the user.
        $this->get('http://foo.localhost/dashboard')
            ->assertRedirect('http://foo.localhost/login');

        // We impersonate the user
        $token = tenancy()->impersonate($tenant, $user->id, '/dashboard');
        $this->get('http://foo.localhost/impersonate/' . $token->token)
            ->assertRedirect('http://foo.localhost/dashboard');

        // Now we try to visit the dashboard directly, after impersonating the user.
        $this->get('http://foo.localhost/dashboard')
            ->assertSuccessful()
            ->assertSee('You are logged in as Joe');
    }

    /** @test */
    public function tenant_user_can_be_impersonated_on_a_tenant_path()
    {
        $this->makeLoginRoute();

        Route::middleware(InitializeTenancyByPath::class)->prefix('/{tenant}')->group($this->getRoutes(false));

        $tenant = Tenant::create([
            'id' => 'acme',
            'tenancy_db_name' => 'db' . Str::random(16),
        ]);
        $this->migrateTenants();
        $user = $tenant->run(function () {
            return ImpersonationUser::create([
                'name' => 'Joe',
                'email' => 'joe@local',
                'password' => bcrypt('secret'),
            ]);
        });

        // We try to visit the dashboard directly, before impersonating the user.
        $this->get('/acme/dashboard')
            ->assertRedirect('/login');

        // We impersonate the user
        $token = tenancy()->impersonate($tenant, $user->id, '/acme/dashboard');
        $this->get('/acme/impersonate/' . $token->token)
            ->assertRedirect('/acme/dashboard');

        // Now we try to visit the dashboard directly, after impersonating the user.
        $this->get('/acme/dashboard')
            ->assertSuccessful()
            ->assertSee('You are logged in as Joe');
    }

    /** @test */
    public function tokens_have_a_limited_ttl()
    {
        Route::middleware(InitializeTenancyByDomain::class)->group($this->getRoutes());

        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);
        $this->migrateTenants();
        $user = $tenant->run(function () {
            return ImpersonationUser::create([
                'name' => 'Joe',
                'email' => 'joe@local',
                'password' => bcrypt('secret'),
            ]);
        });

        // We impersonate the user
        $token = tenancy()->impersonate($tenant, $user->id, '/dashboard');
        $token->update([
            'created_at' => Carbon::now()->subtract(CarbonInterval::make('100s')),
        ]);

        $this->followingRedirects()
            ->get('http://foo.localhost/impersonate/' . $token->token)
            ->assertStatus(403);
    }

    /** @test */
    public function tokens_are_deleted_after_use()
    {
        Route::middleware(InitializeTenancyByDomain::class)->group($this->getRoutes());

        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);
        $this->migrateTenants();
        $user = $tenant->run(function () {
            return ImpersonationUser::create([
                'name' => 'Joe',
                'email' => 'joe@local',
                'password' => bcrypt('secret'),
            ]);
        });

        // We impersonate the user
        $token = tenancy()->impersonate($tenant, $user->id, '/dashboard');

        $this->assertNotNull(ImpersonationToken::find($token->token));

        $this->followingRedirects()
            ->get('http://foo.localhost/impersonate/' . $token->token)
            ->assertSuccessful()
            ->assertSee('You are logged in as Joe');

        $this->assertNull(ImpersonationToken::find($token->token));
    }

    /** @test */
    public function impersonation_works_with_multiple_models_and_guards()
    {
        config([
            'auth.guards.another' => [
                'driver' => 'session',
                'provider' => 'another_users',
            ],
            'auth.providers.another_users' => [
                'driver' => 'eloquent',
                'model' => AnotherImpersonationUser::class,
            ],
        ]);

        Auth::extend('another', function ($app, $name, array $config) {
            return new SessionGuard($name, Auth::createUserProvider($config['provider']), session());
        });

        Route::middleware(InitializeTenancyByDomain::class)->group($this->getRoutes(true, 'another'));

        $tenant = Tenant::create();
        $tenant->domains()->create([
            'domain' => 'foo.localhost',
        ]);
        $this->migrateTenants();
        $user = $tenant->run(function () {
            return AnotherImpersonationUser::create([
                'name' => 'Joe',
                'email' => 'joe@local',
                'password' => bcrypt('secret'),
            ]);
        });

        // We try to visit the dashboard directly, before impersonating the user.
        $this->get('http://foo.localhost/dashboard')
            ->assertRedirect('http://foo.localhost/login');

        // We impersonate the user
        $token = tenancy()->impersonate($tenant, $user->id, '/dashboard', 'another');
        $this->get('http://foo.localhost/impersonate/' . $token->token)
            ->assertRedirect('http://foo.localhost/dashboard');

        // Now we try to visit the dashboard directly, after impersonating the user.
        $this->get('http://foo.localhost/dashboard')
            ->assertSuccessful()
            ->assertSee('You are logged in as Joe');

        Tenant::first()->run(function () {
            $this->assertSame('Joe', auth()->guard('another')->user()->name);
            $this->assertSame(null, auth()->guard('web')->user());
        });
    }
}

class ImpersonationUser extends Authenticable
{
    protected $guarded = [];
    protected $table = 'users';
}

class AnotherImpersonationUser extends Authenticable
{
    protected $guarded = [];
    protected $table = 'users';
}
