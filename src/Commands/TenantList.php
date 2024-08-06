<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Stancl\Tenancy\Contracts\SingleDomainTenant;
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
            $domains = $tenant instanceof SingleDomainTenant ? collect([$tenant->domain]) : $tenant->domains?->pluck('domain');

            $this->components->twoColumnDetail($this->tenantCLI($tenant), $this->domainsCLI($domains));
        }

        $this->newLine();

        return 0;
    }

    /** Generate the visual CLI output for the tenant name. */
    protected function tenantCLI(Model&Tenant $tenant): string
    {
        return "<fg=yellow>{$tenant->getTenantKeyName()}: {$tenant->getTenantKey()}</>";
    }

    /** Generate the visual CLI output for the domain names. */
    /**
     * @param Collection<int|string, string>|null $domains
     */
    protected function domainsCLI(?Collection $domains): ?string
    {
        if (! $domains) {
            return null;
        }

        return "<fg=blue;options=bold>{$domains->implode(' / ')}</>";
    }
}
