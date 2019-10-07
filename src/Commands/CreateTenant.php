<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\Tenant;

class CreateTenant extends Command
{
    protected $signature = 'tenants:create
                            {--d|domain=* : The tenant\'s domains.}
                            {data?* : The tenant\'s data. Separate keys and values by `=`, e.g. `plan=free`.}';

    protected $description = 'Create a tenant.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $tenant = Tenant::new()
            ->withDomains($this->getDomains())
            ->withData($this->getData())
            ->save();

        $this->info($tenant->id);
    }

    public function getDomains(): array
    {
        return $this->option('domain');
    }

    public function getData(): array
    {
        return array_reduce($this->argument('data'), function ($data, $pair) {
            [$key, $value] = explode('=', $pair, 2);
            $data[$key] = $value;

            return $data;
        }, []);
    }
}
