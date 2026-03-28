<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand;
use Illuminate\Database\Migrations\Migrator;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Stancl\Tenancy\Concerns\ParallelTenantMigrator;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\DatabaseMigrated;
use Stancl\Tenancy\Events\MigratingDatabase;
use Symfony\Component\Console\Input\InputOption;

class Migrate extends MigrateCommand
{
    use HasATenantsOption {
        getOptions as private tenantsCommandOptions;
    }
    use DealsWithMigrations, ExtendsLaravelCommand, ParallelTenantMigrator;

    protected $description = 'Run migrations for tenant(s)';

    protected static function getTenantCommandName(): string
    {
        return 'tenants:migrate';
    }

    public function __construct(Migrator $migrator, Dispatcher $dispatcher)
    {
        parent::__construct($migrator, $dispatcher);

        $this->specifyParameters();
    }

    protected function getOptions()
    {
        return array_merge([
            [
                'parallel-batch-size',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum concurrent tenant migrations per batch when using --parallel',
                '10',
            ],
        ], $this->tenantsCommandOptions());
    }

    public function handle(): int
    {
        foreach (config('tenancy.migration_parameters') as $parameter => $value) {
            if (! $this->input->hasParameterOption($parameter)) {
                $this->input->setOption(ltrim($parameter, '-'), $value);
            }
        }

        if (! $this->confirmToProceed()) {
            return static::FAILURE;
        }

        if ($this->option('parallel')) {
            return $this->runParallel();
        }

        $this->runMigrationForTenants($this->option('tenants'));

        return static::SUCCESS;
    }

    private function runParallel(): int
    {
        if (! class_exists(\Illuminate\Support\Facades\Concurrency::class)) {
            $this->error('Parallel tenant migrations require Laravel 11 or newer (Concurrency facade).');

            return static::FAILURE;
        }

        $keys = $this->tenantKeys();
        if ($keys === []) {
            $this->info('No tenants to migrate.');

            return static::SUCCESS;
        }

        $this->info('Running migrations in parallel');

        $this->runParallelTenantBatches(
            $keys,
            max(1, (int) $this->option('parallel-batch-size')),
            function (int $batchIndex, int $batchTotal, array $batch): void {
                $n = count($batch);
                $this->line(sprintf(
                    'Parallel batch %d/%d (%d tenant%s)',
                    $batchIndex + 1,
                    $batchTotal,
                    $n,
                    $n === 1 ? '' : 's'
                ));
            }
        );

        return static::SUCCESS;
    }

    /**
     * @return list<string|int>
     */
    private function tenantKeys(): array
    {
        $keys = [];
        foreach ($this->getTenants() as $tenant) {
            $keys[] = $tenant instanceof Tenant ? $tenant->getTenantKey() : $tenant;
        }

        return $keys;
    }

    protected function runMigrationForTenants(?array $tenants = []): void
    {
        tenancy()->runForMultiple($tenants, function ($tenant) {
            $this->line("Tenant: {$tenant->getTenantKey()}");

            event(new MigratingDatabase($tenant));

            parent::handle();

            event(new DatabaseMigrated($tenant));
        });
    }
}
