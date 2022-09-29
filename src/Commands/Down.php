<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Foundation\Console\DownCommand;
use Stancl\Tenancy\Concerns\HasATenantsOption;

class Down extends DownCommand
{
    use HasATenantsOption;

    protected $signature = 'tenants:down
         {--redirect= : The path that users should be redirected to}
         {--retry= : The number of seconds after which the request may be retried}
         {--refresh= : The number of seconds after which the browser may refresh}
         {--secret= : The secret phrase that may be used to bypass maintenance mode}
         {--status=503 : The status code that should be used when returning the maintenance mode response}';

    protected $description = 'Put tenants into maintenance mode.';

    public function handle(): void
    {
        // The base down command is heavily used. Instead of saving the data inside a file,
        // the data is stored the tenant database, which means some Laravel features
        // are not available with tenants.

        $payload = $this->getDownDatabasePayload();

        // This runs for all tenants if no --tenants are specified
        tenancy()->runForMultiple($this->option('tenants'), function ($tenant) use ($payload) {
            $this->line("Tenant: {$tenant['id']}");
            $tenant->putDownForMaintenance($payload);
        });

        $this->comment('Tenants are now in maintenance mode.');
    }

    /** Get the payload to be placed in the "down" file. */
    protected function getDownDatabasePayload()
    {
        return [
            'except' => $this->excludedPaths(),
            'redirect' => $this->redirectPath(),
            'retry' => $this->getRetryTime(),
            'refresh' => $this->option('refresh'),
            'secret' => $this->option('secret'),
            'status' => (int) $this->option('status', 503),
        ];
    }
}
