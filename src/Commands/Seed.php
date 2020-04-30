<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Stancl\Tenancy\DatabaseManager;
use Stancl\Tenancy\Traits\HasATenantsOption;

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
        foreach (config('tenancy.seeder_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return;
        }

        tenancy()->all($this->option('tenants'))->each(function ($tenant) {
            $this->line("Tenant: {$tenant['id']}");

            $this->input->setOption('database', $tenant->getConnectionName());

            $tenant->run(function () {
                // Seed
                parent::handle();
            });
        });
    }
}
