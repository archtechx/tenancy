<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;
use Stancl\Tenancy\Tests\Etc\ExampleSeeder;

class CommandsTest extends TestCase
{
    public $autoInitTenancy = false;

    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.migrations_directory' => database_path('../migrations')]);
    }

    /** @test */
    public function migrate_command_doesnt_change_the_db_connection()
    {
        $this->assertFalse(Schema::hasTable('users'));

        $old_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();
        Artisan::call('tenants:migrate');
        $new_connection_name = app(\Illuminate\Database\DatabaseManager::class)->connection()->getName();

        $this->assertFalse(Schema::hasTable('users'));
        $this->assertEquals($old_connection_name, $new_connection_name);
        $this->assertNotEquals('tenant', $new_connection_name);
    }

    /** @test */
    public function migrate_command_works_without_options()
    {
        $this->assertFalse(Schema::hasTable('users'));
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function migrate_command_works_with_tenants_option()
    {
        $tenant = tenant()->create('test.localhost');
        Artisan::call('tenants:migrate', [
            '--tenants' => [$tenant['uuid']],
        ]);

        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('localhost');
        $this->assertFalse(Schema::hasTable('users'));

        tenancy()->init('test.localhost');
        $this->assertTrue(Schema::hasTable('users'));
    }

    /** @test */
    public function rollback_command_works()
    {
        Artisan::call('tenants:migrate');
        $this->assertFalse(Schema::hasTable('users'));
        tenancy()->init('localhost');
        $this->assertTrue(Schema::hasTable('users'));
        Artisan::call('tenants:rollback');
        $this->assertFalse(Schema::hasTable('users'));
    }

    /** @test */
    public function seed_command_works()
    {
        $this->markTestIncomplete();
    }

    /** @test */
    public function database_connection_is_switched_to_default()
    {
        $originalDBName = DB::connection()->getDatabaseName();

        Artisan::call('tenants:migrate');
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        Artisan::call('tenants:seed', ['--class' => ExampleSeeder::class]);
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        Artisan::call('tenants:rollback');
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());

        $this->run_commands_works();
        $this->assertSame($originalDBName, DB::connection()->getDatabaseName());
    }

    /** @test */
    public function database_connection_is_switched_to_default_when_tenancy_has_been_initialized()
    {
        tenancy()->init('localhost');

        $this->database_connection_is_switched_to_default();
    }

    /** @test */
    public function run_commands_works()
    {
        $uuid = tenant()->create('run.localhost')['uuid'];

        Artisan::call('tenants:migrate', ['--tenants' => $uuid]);

        $this->artisan("tenants:run foo --tenants=$uuid --argument='a=foo' --option='b=bar' --option='c=xyz'")
            ->expectsOutput("User's name is Test command")
            ->expectsOutput('foo')
            ->expectsOutput('xyz');
    }

    // todo check that multiple tenants can be migrated at once using all database engines

    /** @test */
    public function install_command_works()
    {
        if (! \is_dir($dir = app_path('Http'))) {
            \mkdir($dir, 0777, true);
        }
        if (! \is_dir($dir = base_path('routes'))) {
            \mkdir($dir, 0777, true);
        }

        \file_put_contents(app_path('Http/Kernel.php'), "<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected \$middleware = [
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected \$middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            'throttle:60,1',
            'bindings',
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected \$routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ];

    /**
     * The priority-sorted list of middleware.
     *
     * This forces non-global middleware to always be in the given order.
     *
     * @var array
     */
    protected \$middlewarePriority = [
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\Authenticate::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];
}
");

        $this->artisan('tenancy:install')
            ->expectsQuestion('Do you want to publish the default database migration?', 'yes');
        $this->assertFileExists(base_path('routes/tenant.php'));
        $this->assertFileExists(base_path('config/tenancy.php'));
        $this->assertFileExists(database_path('migrations/2019_08_08_000000_create_tenants_table.php'));
        $this->assertDirectoryExists(database_path('migrations/tenant'));
        $this->assertSame("<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected \$middleware = [
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected \$middlewareGroups = [
        'web' => [
            \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],

        'api' => [
            'throttle:60,1',
            'bindings',
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected \$routeMiddleware = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
    ];

    /**
     * The priority-sorted list of middleware.
     *
     * This forces non-global middleware to always be in the given order.
     *
     * @var array
     */
    protected \$middlewarePriority = [
        \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,
        \Stancl\Tenancy\Middleware\InitializeTenancy::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \App\Http\Middleware\Authenticate::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];
}
", \file_get_contents(app_path('Http/Kernel.php')));
    }
}
