<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Foundation\Console\DownCommand;
use Stancl\Tenancy\Concerns\HasTenantOptions;

class Down extends DownCommand
{
    use HasTenantOptions;

    protected $signature = 'tenants:down
         {--redirect= : The path that users should be redirected to}
         {--retry= : The number of seconds after which the request may be retried}
         {--refresh= : The number of seconds after which the browser may refresh}
         {--secret= : The secret phrase that may be used to bypass maintenance mode}
         {--status=503 : The status code that should be used when returning the maintenance mode response}';

    protected $description = 'Put tenants into maintenance mode.';

    public function handle(): int
    {
        $payload = $this->getDownDatabasePayload();

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) use ($payload) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");
            $tenant->putDownForMaintenance($payload);
        });

        $this->components->info('Tenants are now in maintenance mode.');

        return 0;
    }

    /**
     * Get the payload to be placed in the "down" file. This
     * payload is the same as the original function
     * but without the 'template' option.
     */
    protected function getDownDatabasePayload(): array
    {
        return [
            'except' => $this->excludedPaths(),
            'redirect' => $this->redirectPath(),
            'retry' => $this->getRetryTime(),
            'refresh' => $this->option('refresh'),
            'secret' => $this->option('secret'),
            'status' => (int) ($this->option('status') ?? 503),
        ];
    }
}
