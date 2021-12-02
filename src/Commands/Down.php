<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Concerns\HasATenantsOption;

class Down extends Command
{
    use HasATenantsOption;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */

    protected $signature = 'tenants:down
                                 {--time= : The time when the app has been set to maintenance mode}
                                 {--message= : Message to display}
                                 {--retry= : The number of seconds after which the request may be retried}
                                 {--allowed=* : List of IPs allowed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Put tenants into maintenance';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) {
            $this->line("Tenant: {$tenant['id']}");
            $tenant->putDownForMaintenance([
                'time' => $this->option('time'),
                'message' => $this->option('message'),
                'retry' => $this->option('retry'),
                'allowed' => $this->option('allowed'),
            ]);
        });

        $this->comment('Tenants are now in maintenance mode.');
    }
}
