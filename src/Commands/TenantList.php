<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;

class TenantList extends Command
{
    protected $signature = 'tenants:list';

    protected $description = 'List tenants.';

    public function handle(): int
    {
        $tenants = tenancy()->query()->cursor();

        $this->components->info("Listing {$tenants->count()} tenants.");

        foreach ($tenants as $tenant) {
            /** @var Model&Tenant $tenant */
            $this->components->twoColumnDetail($this->tenantCli($tenant), $this->domainsCli($tenant));
        }

        return 0;
    }

    /**
     * Generate the visual cli output for the tenant name
     *
     * @param  Model  $tenant
     * @return string
     */
    protected function tenantCli(Model $tenant): string
    {
        return "<fg=yellow>{$tenant->getTenantKeyName()}: {$tenant->getTenantKey()}</>";
    }

    /**
     * Generate the visual cli output for the domain names
     *
     * @param  Model  $tenant
     * @return string|null
     */
    protected function domainsCli(Model $tenant): ?string
    {
        if (! $tenant->domains) {

            return null;
        }

        return "<fg=blue;options=bold>{$tenant->domains->pluck('domain')->implode(' ; ')}</>";
    }
}
