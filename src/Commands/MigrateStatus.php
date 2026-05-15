<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Database\Console\Migrations\StatusCommand;
use Illuminate\Database\Migrations\Migrator;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\ExtendsLaravelCommand;
use Stancl\Tenancy\Concerns\HasATenantsOption;

class MigrateStatus extends StatusCommand
{
    use HasATenantsOption, DealsWithMigrations, ExtendsLaravelCommand;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the status of each migration for tenant(s).';

    protected static function getTenantCommandName(): string
    {
        return 'tenants:migrate-status';
    }

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(Migrator $migrator)
    {
        parent::__construct($migrator);

        $this->specifyTenantSignature();
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
                if ($this->getDefinition()->hasOption(ltrim($parameter, '-'))) {
                    $this->input->setOption(ltrim($parameter, '-'), $value);
                }
            }
        }

        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->line("Tenant: {$tenant->getTenantKey()}");

            parent::handle();
        });
    }
}
