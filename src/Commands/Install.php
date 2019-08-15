<?php

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
        $this->comment('Installing stancl/tenancy... ⌛');
        $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'config',
            ]);
        $this->info('✔️  Created config/tenancy.php');

        file_put_contents(app_path('Http/Kernel.php'), str_replace(
            "protected \$middlewarePriority = [",
            "protected \$middlewarePriority = [\n        \Stancl\Tenancy\Middleware\InitializeTenancy::class",
            file_get_contents(app_path('Http/Kernel.php'))
        ));
        $this->info('✔️  Set middleware priority');

        file_put_contents(base_path('routes/tenant.php'),
"<?php

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register tenat routes for your application. These
| routes are loaded by the TenantRouteServiceProvider within a group
| which contains the \"InitializeTenancy\" middleware. Good luck!
|

Route::get('/your/application/homepage', function () {
    return 'This is your multi-tenant application. The uuid of the current tenant is ' . tenant('uuid');
});
*/
");
        $this->info('✔️  Created routes/tenant.php');

        $this->line('');
        $this->line("The package lets you store data about tenants either in Redis or in a relational database like MySQL. If you're going to use the database storage, you need to create a tenants table.");
        $migration = $this->ask('Do you want to publish the default database migration? [yes/no]', 'yes');
        if (\strtolower($migration) === 'yes') {
            $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'migrations',
            ]);
            $this->info('✔️  Created migration.');
        }

        $this->line('');
        $this->info('✔️  stancl/tenancy installed successfully ✨.');
    }
}
