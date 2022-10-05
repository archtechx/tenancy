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

    public function handle(): void
    {
        $this->info('Listing all tenants.');

        $tenants = tenancy()->query()->cursor();

        foreach ($tenants as $tenant) {
            /** @var Model&Tenant $tenant */
            if ($tenant->domains) {
                $this->line("[Tenant] {$tenant->getTenantKeyName()}: {$tenant->getTenantKey()} @ " . implode('; ', $tenant->domains->pluck('domain')->toArray() ?? []));
            } else {
                $this->line("[Tenant] {$tenant->getTenantKeyName()}: {$tenant->getTenantKey()}");
            }
        }
    }
}
