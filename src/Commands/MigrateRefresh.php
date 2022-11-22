<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\DealsWithMigrations;
use Stancl\Tenancy\Concerns\HasATenantsOption;
use Symfony\Component\Console\Input\InputOption;

final class MigrateRefresh extends Command
{
    use HasATenantsOption, DealsWithMigrations;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset and re-run all migrations';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('--path', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The path(s) to the migrations files to be executed', null);

        $this->setName('tenants:migrate-refresh');
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->info('Rolling back migrations.');
            $this->call('migrate:refresh', array_filter([
                '--database' => 'tenant',
                '--path' => $this->option('path'),
                '--force' => true,
            ]));

        });

        $this->info('Done.');
    }
}
