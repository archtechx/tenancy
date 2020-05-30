<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Events\DatabaseSeeded;
use Stancl\Tenancy\Events\SeedingDatabase;

class Seed extends SeedCommand
{
    use HasATenantsOption;

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
    public function __construct(ConnectionResolverInterface $resolver)
    {
        parent::__construct($resolver);

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

        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->line("Tenant: {$tenant['id']}");

            event(new SeedingDatabase($tenant));

            // Seed
            parent::handle();

            event(new DatabaseSeeded($tenant));
        });
    }
}
