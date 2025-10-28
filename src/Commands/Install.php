<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class Install extends Command
{
    protected $signature = 'tenancy:install';

    protected $description = 'Install Tenancy for Laravel.';

    public function handle(): int
    {
        $this->step(
            name: 'Publishing config file',
            tag: 'config',
            file: 'config/tenancy.php',
            newLineBefore: true,
        );

        $this->step(
            name: 'Publishing routes',
            tag: 'routes',
            file: 'routes/tenant.php',
        );

        $this->step(
            name: 'Publishing service provider',
            tag: 'providers',
            file: 'app/Providers/TenancyServiceProvider.php',
        );

        $this->step(
            name: 'Publishing migrations',
            tag: 'migrations',
            files: [
                'database/migrations/2019_09_15_000010_create_tenants_table.php',
                'database/migrations/2019_09_15_000020_create_domains_table.php',
            ],
            warning: 'Migrations already exist',
        );

        $this->step(
            name: 'Creating [database/migrations/tenant] folder',
            task: fn () => mkdir(database_path('migrations/tenant')),
            unless: is_dir(database_path('migrations/tenant')),
            warning: 'Folder [database/migrations/tenant] already exists.',
            newLineAfter: true,
        );

        $this->components->success('✨️ Tenancy for Laravel successfully installed.');

        if (! $this->option('no-interaction')) {
            $this->askForSupport();
        }

        return 0;
    }

    /**
     * Run a step of the installation process.
     *
     * @param string $name The name of the step.
     * @param Closure|null $task The task code.
     * @param bool $unless Condition specifying when the task should NOT run.
     * @param string|null $warning Warning shown when the $unless condition is true.
     * @param string|null $file Name of the file being added.
     * @param string|null $tag The tag being published.
     * @param array|null $files Names of files being added.
     * @param bool $newLineBefore Should a new line be printed after the step.
     * @param bool $newLineAfter Should a new line be printed after the step.
     */
    protected function step(
        string $name,
        ?Closure $task = null,
        bool $unless = false,
        ?string $warning = null,
        ?string $file = null,
        ?string $tag = null,
        ?array $files = null,
        bool $newLineBefore = false,
        bool $newLineAfter = false,
    ): void {
        if ($file) {
            $name .= " [$file]"; // Append clickable path to the task name
            $unless = file_exists(base_path($file)); // Make the condition a check for the file's existence
            $warning = "File [$file] already exists."; // Make the warning a message about the file already existing
        }

        if ($tag) {
            $task = fn () => $this->callSilent('vendor:publish', [
                '--provider' => 'Stancl\Tenancy\TenancyServiceProvider',
                '--tag' => $tag,
            ]);
        }

        if ($files) {
            // Show a warning if any of the files already exist
            $unless = count(array_filter($files, fn ($file) => file_exists(base_path($file)))) !== 0;
        }

        if (! $unless) {
            if ($newLineBefore) {
                $this->newLine();
            }

            $this->components->task($name, $task ?? fn () => null);

            if ($files) {
                // Print out a clickable list of the added files
                $this->components->bulletList(array_map(fn (string $file) => "[$file]", $files));
            }

            if ($newLineAfter) {
                $this->newLine();
            }
        } else {
            /** @var string $warning */
            $this->components->warn($warning);
        }
    }

    /** If the user accepts, opens the GitHub project in the browser. */
    public function askForSupport(): void
    {
        if ($this->components->confirm('Would you like to show your support by starring the project on GitHub?', true)) {
            $ghVersion = Process::run('gh --version');
            $starred = false;

            // Make sure the `gh` binary is the actual GitHub CLI and not an unrelated tool
            if ($ghVersion->successful() && str_contains($ghVersion->output(), 'https://github.com/cli/cli')) {
                $starRequest = Process::run('gh api -X PUT user/starred/archtechx/tenancy');
                $starred = $starRequest->successful();
            }

            if ($starred) {
                $this->components->success('Repository starred via gh CLI, thank you!');
            } else {
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
}
