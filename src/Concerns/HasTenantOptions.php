<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Database\Concerns\PendingScope;
use Symfony\Component\Console\Input\InputOption;

/**
 * Adds 'tenants' and 'with-pending' options.
 */
trait HasTenantOptions
{
    protected function getOptions()
    {
        return array_merge([
            ['tenants', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, 'The tenants to run this command for. Leave empty for all tenants', null],
            ['with-pending', null, InputOption::VALUE_NONE, 'Include pending tenants in query'],
        ], parent::getOptions());
    }

    /**
     * @return LazyCollection<int, \Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model>
     */
    protected function getTenants(?array $tenantKeys = null): LazyCollection
    {
        return $this->getTenantsQuery($tenantKeys)->cursor();
    }

    /**
     * @return Builder<\Stancl\Tenancy\Contracts\Tenant&\Illuminate\Database\Eloquent\Model>
     */
    protected function getTenantsQuery(?array $tenantKeys = null): Builder
    {
        return tenancy()->query()
            ->when($tenantKeys, function ($query) use ($tenantKeys) {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $tenantKeys);
            })
            ->when($this->option('tenants'), function ($query) {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $this->option('tenants'));
            })
            ->when(tenancy()->model()::hasGlobalScope(PendingScope::class), function ($query) {
                $query->withPending(config('tenancy.pending.include_in_queries') ?: $this->option('with-pending'));
            });
    }

    public function __construct()
    {
        parent::__construct();

        $this->specifyParameters();
    }
}
