<?php

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Symfony\Component\Console\Input\InputOption;
use Illuminate\Database\Console\Migrations\RollbackCommand;

class Rollback extends RollbackCommand
{
    protected $database;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback migrations for tenant(s).';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Migrator $migrator, DatabaseManager $database)
    {
        parent::__construct($migrator);
        $this->database = $database;

        $this->setName('tenants:rollback');
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

        $this->input->setOption('database', 'tenant');

        tenant()->all($this->option('tenants'))->each(function ($tenant) {
            $this->line("Tenant: {$tenant['uuid']} ({$tenant['domain']})");
            $this->database->connectToTenant($tenant);

            // Migrate
            parent::handle();
        });
    }

    protected function getOptions()
    {
        return array_merge([
            ['tenants', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, '', null]
        ], parent::getOptions());
    }

    protected function getMigrationPaths()
    {
        return [database_path('migrations/tenant')];
    }
}
