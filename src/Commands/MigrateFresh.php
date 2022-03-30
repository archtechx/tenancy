<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Symfony\Component\Console\Input\InputOption;

final class MigrateFresh extends Command
{
    use HasATenantsOption, DealsWithMigrations;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop all tables and re-run all migrations for tenant(s)';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('--drop-views', null, InputOption::VALUE_NONE, 'Drop views along with tenant tables.', null);

        $this->setName('tenants:migrate-fresh');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->info('Dropping tables.');
            $this->call('db:wipe', array_filter([
                '--database' => 'tenant',
                '--drop-views' => $this->option('drop-views'),
                '--force' => true,
            ]));

            $this->info('Migrating.');
            $this->callSilent('tenants:migrate', [
                '--tenants' => [$tenant->getTenantKey()],
                '--force' => true,
            ]);
        });

        $this->info('Done.');
    }
}
