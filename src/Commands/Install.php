<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class Install extends Command
{
    protected $signature = 'tenancy:install';

    protected $description = 'Install stancl/tenancy.';

    public function handle(): int
    {
        $this->newLine();

        $this->components->task('Publishing config file', function () {
            $this->callSilent('vendor:publish', [
                '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                '--tag' => 'config',
            ]);
        });

        $this->newLine();

        if (!file_exists(base_path('routes/tenant.php'))) {
            $this->components->task('Publishing routes', function () {
                $this->callSilent('vendor:publish', [
                    '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                    '--tag' => 'routes',
                ]);
            });
            $this->newLine();
        } else {
            $this->components->warn('File [routes/tenant.php] already existe.');
        }


        $this->components->task('Publishing providers', function () {
            $this->callSilent('vendor:publish', [
                '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                '--tag' => 'providers',
            ]);
        });

        $this->newLine();

        $this->components->task('Publishing migrations', function () {
            $this->callSilent('vendor:publish', [
                '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                '--tag' => 'migrations',
            ]);
        });

        $this->newLine();

        if (!is_dir(database_path('migrations/tenant'))) {
            $this->components->task('Creating database/migrations/tenant folder', function () {
                mkdir(database_path('migrations/tenant'));
            });
        } else {
            $this->components->warn('Folder [database/migrations/tenant] already existe.');
        }

        $this->components->info('✨️ stancl/tenancy installed successfully.');

        $this->askForSupport();

        return 0;
    }

    /**
     * If the user accepts, opens the GitHub project in the browser
     *
     * @return void
     */
    public function askForSupport(): void
    {
        if ($this->components->confirm("Would you like to show your support by starring the project on Github ?", true)) {
            if (PHP_OS_FAMILY === 'Darwin') {
                exec('open https://github.com/archtechx/tenancy');
            }
            if (PHP_OS_FAMILY === 'Windows') {
                exec('start https://github.com/archtechx/tenancy');
            }
            if (PHP_OS_FAMILY === 'Linux') {
                exec('xdg-open https://github.com/archtechx/tenancy');
            }
        }
    }
}
