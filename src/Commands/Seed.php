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

        // Our --tenants/--skip-tenants/--with-pending options only get added automatically
        // when the parent command isn't signature-based. Since Laravel 13.24, SeedCommand is,
        // so we add them ourselves here -- checking first so we don't add them twice on older
        // Laravel versions, where they're already there by this point.
        if (! $this->getDefinition()->hasOption('tenants')) {
            $this->specifyParameters();
        }
    }

    protected function configure(): void
    {
        parent::configure();

        // We inherit SeedCommand's name ('db:seed') since we don't redeclare $name/$signature,
        // so without this we'd overwrite Laravel's own db:seed command (see #1474). configure()
        // always runs after the name is set, regardless of Laravel version, so setting it here
        // is safe no matter which of $name/$signature the installed Laravel version uses.
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
