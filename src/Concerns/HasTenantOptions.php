<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Illuminate\Support\LazyCollection;
use Symfony\Component\Console\Input\InputOption;
use Stancl\Tenancy\Database\Concerns\PendingScope;

/**
 * Adds 'tenants' and 'with-pending' options.
 */
trait HasTenantOptions
{
    protected function getOptions()
    {
        return array_merge([
            ['tenants', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, '', null],
            ['with-pending', null, InputOption::VALUE_OPTIONAL, 'include pending tenants in query', false],
        ], parent::getOptions());
    }

    protected function getWithPendingOption(): bool
    {
        $optionPassedWithoutArgument = is_null($this->option('with-pending'));
        $optionPassedWithArgument = is_string($this->option('with-pending'));

        // E.g. tenants:run --with-pending
        if ($optionPassedWithoutArgument) {
            return true;
        }

        // E.g. tenants:run --with-pending=false
        if ($optionPassedWithArgument) {
            return filter_var($this->option('with-pending'), FILTER_VALIDATE_BOOLEAN);
        }

        // Option not passed, e.g. tenants:run
        return config('tenancy.pending.include_in_queries');
    }

    protected function getTenants(): LazyCollection
    {
        return tenancy()
            ->query()
            ->when($this->option('tenants'), function ($query) {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $this->option('tenants'));
            })
            ->when(tenancy()->model()::hasGlobalScope(PendingScope::class), function ($query) {
                $query->withPending($this->getWithPendingOption());
            })
            ->cursor();
    }

    public function __construct()
    {
        parent::__construct();

        $this->specifyParameters();
    }
}
