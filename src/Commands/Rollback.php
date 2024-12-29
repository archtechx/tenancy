<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Concerns\ParallelCommand;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Stancl\Tenancy\Events\DatabaseRolledBack;
use Stancl\Tenancy\Events\RollingBackDatabase;
use Symfony\Component\Console\Output\OutputInterface as OI;

class Rollback extends RollbackCommand
{
    use HasTenantOptions, DealsWithMigrations, ExtendsLaravelCommand, ParallelCommand;

    protected $description = 'Rollback migrations for tenant(s).';

    public function __construct(Migrator $migrator)
    {
        parent::__construct($migrator);

        $this->addProcessesOption();
        $this->addOption('skip-failing', description: 'Continue execution if migration fails for a tenant');

        $this->specifyTenantSignature();
    }

    public function handle(): int
    {
        $this->setParameterDefaults();

        if (! $this->confirmToProceed()) {
            return 1;
        }

        if ($this->getProcesses() > 1) {
            return $this->runConcurrently($this->getTenantChunks()->map(function ($chunk) {
                return $this->getTenants($chunk);
            }));
        }

        return $this->rollbackTenants($this->getTenants()) ? 0 : 1;
    }

    protected static function getTenantCommandName(): string
    {
        return 'tenants:rollback';
    }

    protected function childHandle(mixed ...$args): bool
    {
        $chunk = $args[0];

        return $this->rollbackTenants($chunk);
    }

    /**
     * @param LazyCollection<covariant int|string, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> $tenants
     */
    protected function rollbackTenants(LazyCollection $tenants): bool
    {
        $success = true;

        foreach ($tenants as $tenant) {
            try {
                $this->components->info("Rolling back tenant {$tenant->getTenantKey()}");

                $tenant->run(function ($tenant) use (&$success) {
                    event(new RollingBackDatabase($tenant));

                    $verbosity = $this->output->getVerbosity();

                    if ($this->runningConcurrently) {
                        // The output gets messy when multiple processes are writing to the same stdout
                        $this->output->setVerbosity(OI::VERBOSITY_QUIET);
                    }

                    try {
                        // Rollback
                        if (parent::handle() !== 0) {
                            $success = false;
                        }
                    } finally {
                        $this->output->setVerbosity($verbosity);
                    }

                    if ($this->runningConcurrently) {
                        $this->components->success("Rolled back tenant {$tenant->getTenantKey()}");
                    }

                    event(new DatabaseRolledBack($tenant));
                });
            } catch (TenantDatabaseDoesNotExistException|QueryException $e) {
                $this->components->error("Rollback failed for tenant {$tenant->getTenantKey()}: {$e->getMessage()}");
                $success = false;

                if (! $this->option('skip-failing')) {
                    throw $e;
                }
            }
        }

        return $success;
    }

    protected function setParameterDefaults(): void
    {
        // Parameters that this command doesn't support, but can be in tenancy.migration_parameters
        $ignoredParameters = [
            '--seed',
            '--seeder',
            '--isolated',
            '--schema-path',
        ];

        foreach (config('tenancy.migration_parameters') as $parameter => $value) {
            // Only set the default if the option isn't set
            if (! in_array($parameter, $ignoredParameters) && ! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }
    }
}
