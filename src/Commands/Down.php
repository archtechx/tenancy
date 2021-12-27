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

    protected $signature = 'tenancy:down
                                 {--redirect= : The path that users should be redirected to}
                                 {--retry= : The number of seconds after which the request may be retried}
                                 {--refresh= : The number of seconds after which the browser may refresh}
                                 {--secret= : The secret phrase that may be used to bypass maintenance mode}
                                 {--status=503 : The status code that should be used when returning the maintenance mode response}';

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
                'redirect' => $this->option('redirect'),
                'retry' => $this->option('retry'),
                'refresh' => $this->option('refresh'),
                'secret' => $this->option('secret'),
                'status' => $this->option('status'),
            ]);
        });

        $this->comment('Tenants are now in maintenance mode.');
    }
}
