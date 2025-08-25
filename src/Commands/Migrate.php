<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Concerns\ParallelCommand;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\MigratingDatabase;
use Symfony\Component\Console\Output\OutputInterface as OI;

class Migrate extends MigrateCommand
{
    use HasTenantOptions, DealsWithMigrations, ExtendsLaravelCommand, ParallelCommand;

    protected $description = 'Run migrations for tenant(s)';

    protected static function getTenantCommandName(): string
    {
        return 'tenants:migrate';
    }

    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);

        $this->addOption('skip-failing', description: 'Continue execution if migration fails for a tenant');
        $this->addProcessesOption();

        $this->specifyParameters();
    }

    public function handle(): int
    {
        foreach (config('tenancy.migration_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        $originalTemplateConnection = config('tenancy.database.template_tenant_connection');

        if ($database = $this->input->getOption('database')) {
            config(['tenancy.database.template_tenant_connection' => $database]);
        }

        if ($this->getProcesses() > 1) {
            $code = $this->runConcurrently($this->getTenantChunks()->map(function ($chunk) {
                return $this->getTenants($chunk);
            }));
        } else {
            $code = $this->migrateTenants($this->getTenants()) ? 0 : 1;
        }

        // Reset the template tenant connection to the original one
        config(['tenancy.database.template_tenant_connection' => $originalTemplateConnection]);

        return $code;
    }

    protected function childHandle(mixed ...$args): bool
    {
        $chunk = $args[0];

        return $this->migrateTenants($chunk);
    }

    /**
     * @param LazyCollection<covariant int|string, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model> $tenants
     */
    protected function migrateTenants(LazyCollection $tenants): bool
    {
        $success = true;

        foreach ($tenants as $tenant) {
            try {
                $this->components->info("Migrating tenant {$tenant->getTenantKey()}");

                $tenant->run(function ($tenant) use (&$success) {
                    event(new MigratingDatabase($tenant));

                    $verbosity = $this->output->getVerbosity();

                    if ($this->runningConcurrently) {
                        // The output gets messy when multiple processes are writing to the same stdout
                        $this->output->setVerbosity(OI::VERBOSITY_QUIET);
                    }

                    try {
                        // Migrate
                        if (parent::handle() !== 0) {
                            $success = false;
                        }
                    } finally {
                        $this->output->setVerbosity($verbosity);
                    }

                    if ($this->runningConcurrently) {
                        // todo@cli the Migrating info above always has extra spaces, and the success below does WHEN there is work that got done by the block above. same in Rollback
                        $this->components->success("Migrated tenant {$tenant->getTenantKey()}");
                    }

                    event(new DatabaseMigrated($tenant));
                });
            } catch (TenantDatabaseDoesNotExistException|QueryException $e) {
                $this->components->error("Migration failed for tenant {$tenant->getTenantKey()}: {$e->getMessage()}");
                $success = false;

                if (! $this->option('skip-failing')) {
                    throw $e;
                }
            }
        }

        return $success;
    }
}
