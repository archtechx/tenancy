<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;

class Up extends Command
{
    use HasATenantsOption;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'tenants:up';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Put tenants out of maintenance';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->line("Tenant: {$tenant['id']}");
            $tenant->bringUpFromMaintenance();
        });

        $this->comment('Tenants are now out of maintenance mode.');
    }
}
