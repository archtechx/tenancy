<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\QueryException;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Database\Exceptions\TenantDatabaseDoesNotExistException;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\MigratingDatabase;

class Migrate extends MigrateCommand
{
    use HasTenantOptions, DealsWithMigrations, ExtendsLaravelCommand;

    protected $description = 'Run migrations for tenant(s)';

    protected static function getTenantCommandName(): string
    {
        return 'tenants:migrate';
    }

    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);

        $this->addOption('skip-failing', description: 'Continue execution if migration fails for a tenant');

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

        foreach ($this->getTenants() as $tenant) {
            try {
                $this->components->info("Migrating tenant {$tenant->getTenantKey()}");

                $tenant->run(function ($tenant) {
                    event(new MigratingDatabase($tenant));
                    // Migrate
                    parent::handle();

                    event(new DatabaseMigrated($tenant));
                });
            } catch (TenantDatabaseDoesNotExistException|QueryException $e) {
                if (! $this->option('skip-failing')) {
                    throw $e;
                }

                $this->components->warn("Migration failed for tenant {$tenant->getTenantKey()}: {$e->getMessage()}");
            }
        }

        return 0;
    }
}
