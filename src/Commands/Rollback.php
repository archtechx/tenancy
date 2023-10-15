<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Migrations\Migrator;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Events\DatabaseRolledBack;
use Stancl\Tenancy\Events\RollingBackDatabase;

class Rollback extends RollbackCommand
{
    use HasTenantOptions, DealsWithMigrations, ExtendsLaravelCommand;

    protected $description = 'Rollback migrations for tenant(s).';

    public function __construct(Migrator $migrator)
    {
        parent::__construct($migrator);

        $this->specifyTenantSignature();
    }

    public function handle(): int
    {
        $this->setParameterDefaults();

        if (! $this->confirmToProceed()) {
            return 1;
        }

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");

            event(new RollingBackDatabase($tenant));

            // Rollback
            parent::handle();

            event(new DatabaseRolledBack($tenant));
        });

        return 0;
    }

    protected static function getTenantCommandName(): string
    {
        return 'tenants:rollback';
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
