<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Events\MigratingDatabase;

class Migrate extends MigrateCommand
{
    use HasATenantsOption, DealsWithMigrations;

    protected $database;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run migrations for tenant(s)';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Migrator $migrator, DatabaseManager $database)
    {
        parent::__construct($migrator);
        $this->database = $database;

        $this->setName('tenants:migrate');
        $this->specifyParameters();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        foreach (config('tenancy.migration_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return;
        }

        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->line("Tenant: {$tenant['id']}");

            event(new MigratingDatabase($tenant));
            
            // Migrate
            parent::handle();

            event(new DatabaseMigrated($tenant));
        });
    }
}
