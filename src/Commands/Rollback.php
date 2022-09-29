<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\RollbackCommand;
use Illuminate\Database\Migrations\Migrator;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Events\DatabaseRolledBack;
use Stancl\Tenancy\Events\RollingBackDatabase;

class Rollback extends RollbackCommand
{
    use HasATenantsOption, ExtendsLaravelCommand;

    protected $description = 'Rollback migrations for tenant(s).';

    public function __construct(Migrator $migrator)
    {
        parent::__construct($migrator);

        $this->specifyTenantSignature();
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

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            $this->line("Tenant: {$tenant->getTenantKey()}");

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
}
