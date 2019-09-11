<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Traits\DealsWithMigrations;
use Stancl\Tenancy\Traits\HasATenantsOption;

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
        if (! $this->confirmToProceed()) {
            return;
        }

        tenant()->all($this->option('tenants'))->each(function ($tenant) {
            $this->line("Tenant: {$tenant['uuid']} ({$tenant['domain']})");

            // See Illuminate\Database\Migrations\DatabaseMigrationRepository::getConnection.
            // Database connections are cached by Illuminate\Database\ConnectionResolver.
            $this->input->setOption('database', 'tenant');
            $this->database->connectToTenant($tenant); // todo test that this works with multiple tenants with MySQL

            // Migrate
            parent::handle();
        });

        if (tenancy()->initialized) {
            tenancy()->switchDatabaseConnection();
        } else {
            $this->database->disconnect();
        }
    }
}
