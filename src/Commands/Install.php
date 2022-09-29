<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class Install extends Command
{
    protected $signature = 'tenancy:install';

    protected $description = 'Install stancl/tenancy.';

    public function handle(): void
    {
        $this->comment('Installing stancl/tenancy...');
        $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'config',
        ]);
        $this->info('✔️  Created config/tenancy.php');

        if (! file_exists(base_path('routes/tenant.php'))) {
            $this->callSilent('vendor:publish', [
                '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                '--tag' => 'routes',
            ]);
            $this->info('✔️  Created routes/tenant.php');
        } else {
            $this->info('Found routes/tenant.php.');
        }

        $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'providers',
        ]);
        $this->info('✔️  Created TenancyServiceProvider.php');

        $this->callSilent('vendor:publish', [
            '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
            '--tag' => 'migrations',
        ]);
        $this->info('✔️  Created migrations. Remember to run [php artisan migrate]!');

        if (! is_dir(database_path('migrations/tenant'))) {
            mkdir(database_path('migrations/tenant'));
            $this->info('✔️  Created database/migrations/tenant folder.');
        }

        $this->comment('✨️ stancl/tenancy installed successfully.');
    }
}
