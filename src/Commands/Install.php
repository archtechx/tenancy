<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class Install extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenancy:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install stancl/tenancy.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->comment('Installing stancl/tenancy...');
        $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'config',
        ]);
        $this->info('✔️  Created config/tenancy.php');

        $newKernel = $this->setMiddlewarePriority();

        $newKernel = str_replace("'web' => [", "'web' => [
            \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,", $newKernel);

        $newKernel = str_replace("'api' => [", "'api' => [
            \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,", $newKernel);

        file_put_contents(app_path('Http/Kernel.php'), $newKernel);
        $this->info('✔️  Set middleware priority');

        if (! file_exists(base_path('routes/tenant.php'))) {
            file_put_contents(base_path('routes/tenant.php'), file_get_contents(__DIR__ . '/../../assets/tenant_routes.php.stub'));
            $this->info('✔️  Created routes/tenant.php');
        } else {
            $this->info('Found routes/tenant.php.');
        }

        $this->line('');
        $this->line('This package lets you store data about tenants either in Redis or in a relational database like MySQL. To store data about tenants in a relational database, you need a few database tables.');
        if ($this->confirm('Do you wish to publish the migrations that create these tables?', true)) {
            $this->callSilent('vendor:publish', [
                '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                '--tag' => 'migrations',
            ]);
            $this->info('✔️  Created migrations. Remember to run [php artisan migrate]!');
        }

        if (! is_dir(database_path('migrations/tenant'))) {
            mkdir(database_path('migrations/tenant'));
            $this->info('✔️  Created database/migrations/tenant folder.');
        }

        $this->comment('✨️ stancl/tenancy installed successfully.');
    }

    protected function setMiddlewarePriority(): string
    {
        if (app()->version()[0] === '6') {
            return str_replace(
                'protected $middlewarePriority = [',
                "protected \$middlewarePriority = [
        \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,
        \Stancl\Tenancy\Middleware\InitializeTenancy::class,",
                file_get_contents(app_path('Http/Kernel.php'))
            );
        } else {
            return str_replace(
                "];\n}",
                "];\n\n    protected \$middlewarePriority = [
        \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,
        \Stancl\Tenancy\Middleware\InitializeTenancy::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Session\Middleware\AuthenticateSession::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];
}",
                file_get_contents(app_path('Http/Kernel.php'))
            );
        }
    }
}
