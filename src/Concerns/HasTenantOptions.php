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
    /** Value indicating an option wasn't passed */
    protected $optionNotPassedValue = 'not passed';

    protected function getOptions()
    {
        return array_merge([
            ['tenants', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, '', null],
            ['with-pending', null, InputOption::VALUE_OPTIONAL, 'include pending tenants in query', $this->optionNotPassedValue],
        ], parent::getOptions());
    }

    protected function getWithPendingOption(): bool
    {
        $optionPassedWithoutArgument = is_null($this->option('with-pending'));
        $optionPassedWithArgument = $this->option('with-pending') !== $this->optionNotPassedValue;

        // E.g. 'tenants:run --with-pending'
        if ($optionPassedWithoutArgument) {
            return true;
        }

        // E.g. 'tenants:run --with-pending=false'
        // If the passed value can't get converted to a bool (e.g. --with-pending=foo), default to false
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
