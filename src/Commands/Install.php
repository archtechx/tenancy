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

        $newKernel = \str_replace(
            'protected $middlewarePriority = [',
            "protected \$middlewarePriority = [
        \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,
        \Stancl\Tenancy\Middleware\InitializeTenancy::class,",
            \file_get_contents(app_path('Http/Kernel.php'))
        );

        $newKernel = \str_replace("'web' => [", "'web' => [
            \Stancl\Tenancy\Middleware\PreventAccessFromTenantDomains::class,", $newKernel);

        \file_put_contents(app_path('Http/Kernel.php'), $newKernel);
        $this->info('✔️  Set middleware priority');

        \file_put_contents(
            base_path('routes/tenant.php'),
            "<?php

/*
|--------------------------------------------------------------------------
| Tenant Routes
|--------------------------------------------------------------------------
|
| Here is where you can register tenant routes for your application. These
| routes are loaded by the TenantRouteServiceProvider within a group
| which contains the \"InitializeTenancy\" middleware. Good luck!
|
*/

Route::get('/', function () {
    return 'This is your multi-tenant application. The uuid of the current tenant is ' . tenant('uuid');
});
"
        );
        $this->info('✔️  Created routes/tenant.php');

        $this->line('');
        $this->line("This package lets you store data about tenants either in Redis or in a relational database like MySQL. If you're going to use the database storage, you need to create a tenants table.");
        if ($this->confirm('Do you want to publish the default database migration?', true)) {
            $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'migrations',
            ]);
            $this->info('✔️  Created migration.');
        }

        if (! \is_dir(database_path('migrations/tenant'))) {
            \mkdir(database_path('migrations/tenant'));
            $this->info('✔️  Created database/migrations/tenant folder.');
        }

        $this->comment('✨️ stancl/tenancy installed successfully.');
    }
}
