<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Console\Seeds\SeedCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Stancl\Tenancy\Events\DatabaseSeeded;
use Stancl\Tenancy\Events\SeedingDatabase;

class Seed extends SeedCommand
{
    use HasTenantOptions;

    protected $description = 'Seed tenant database(s).';

    public function __construct(ConnectionResolverInterface $resolver)
    {
        parent::__construct($resolver);

        if (! $this->getDefinition()->hasOption('tenants')) {
            $this->specifyParameters();
        }
    }

    protected function configure(): void
    {
        parent::configure();

        $this->setName('tenants:seed');
    }

    public function handle(): int
    {
        foreach (config('tenancy.seeder_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return 1;
        }

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");

            event(new SeedingDatabase($tenant));

            // Seed
            parent::handle();

            event(new DatabaseSeeded($tenant));
        });

        return 0;
    }
}
