<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Symfony\Component\Console\Input\InputOption;

class MigrateFresh extends Command
{
    use HasTenantOptions, DealsWithMigrations;

    protected $description = 'Drop all tables and re-run all migrations for tenant(s)';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('--drop-views', null, InputOption::VALUE_NONE, 'Drop views along with tenant tables.', null);

        $this->setName('tenants:migrate-fresh');
    }

    public function handle(): int
    {
        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");

            $this->components->task('Dropping tables', function () {
                $this->callSilently('db:wipe', array_filter([
                    '--database' => 'tenant',
                    '--drop-views' => $this->option('drop-views'),
                    '--force' => true,
                ]));
            });

            $this->components->task('Migrating', function () use ($tenant) {
                $this->callSilent('tenants:migrate', [
                    '--tenants' => [$tenant->getTenantKey()],
                    '--force' => true,
                ]);
            });
        });

        return 0;
    }
}
