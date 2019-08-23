<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Traits\HasATenantsOption;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Illuminate\Database\ConnectionResolverInterface;

class Seed extends SeedCommand
{
    use HasATenantsOption;

    protected $database;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed tenant database(s).';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(ConnectionResolverInterface $resolver, DatabaseManager $database)
    {
        parent::__construct($resolver);
        $this->database = $database;

        $this->setName('tenants:seed');
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

            // Seed
            parent::handle();
        });

        if (tenancy()->initialized) {
            tenancy()->switchDatabaseConnection();
        } else {
            $this->database->disconnect();
        }
    }
}
