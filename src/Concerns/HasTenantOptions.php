<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

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
            ['tenants', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, '', null],
            ['with-pending', null, InputOption::VALUE_NONE, 'include pending tenants in query'],
        ], parent::getOptions());
    }

    protected function getTenants(): LazyCollection
    {
        return tenancy()->query()
            ->when($this->option('tenants'), function ($query) {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $this->option('tenants'));
            })
            ->when(tenancy()->model()::hasGlobalScope(PendingScope::class), function ($query) {
                $query->withPending(config('tenancy.pending.include_in_queries') ?: $this->option('with-pending'));
            })
            ->cursor();
    }

    public function __construct()
    {
        parent::__construct();

        $this->specifyParameters();
    }
}
