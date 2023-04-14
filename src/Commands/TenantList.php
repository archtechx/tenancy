<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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
            $this->components->twoColumnDetail($this->tenantCLI($tenant), $this->domainsCLI($tenant->domains));
        }

        $this->newLine();

        return 0;
    }

    /**
     * Generate the visual CLI output for the tenant name.
     *
     * @param Model&Tenant $tenant
    */
    protected function tenantCLI(Tenant $tenant): string
    {
        return "<fg=yellow>{$tenant->getTenantKeyName()}: {$tenant->getTenantKey()}</>";
    }

    /** Generate the visual CLI output for the domain names. */
    protected function domainsCLI(?Collection $domains): ?string
    {
        if (! $domains) {
            return null;
        }

        return "<fg=blue;options=bold>{$domains->pluck('domain')->implode(' / ')}</>";
    }
}
