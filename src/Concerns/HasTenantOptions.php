<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;
use Stancl\Tenancy\Database\Concerns\PendingScope;
use Symfony\Component\Console\Input\InputOption;

/**
 * Adds 'tenants', 'skip-tenants', and 'with-pending' options,
 * and the $scopeTenantsQuery hook.
 */
trait HasTenantOptions
{
    /**
     * Scope the query that selects the tenants this command runs for. Each command has its own scope.
     *
     * Example: Migrate::$scopeTenantsQuery = fn (Builder $query) => $query->where('region', config('app.region'));
     */
    public static Closure|null $scopeTenantsQuery = null;

    /**
     * Run the callback with this command's tenants query scope disabled.
     *
     * Used when calling the command for a tenant that was already selected elsewhere.
     */
    public static function withoutTenantsQueryScope(Closure $callback): mixed
    {
        $scope = static::$scopeTenantsQuery;
        static::$scopeTenantsQuery = null;

        try {
            return $callback();
        } finally {
            static::$scopeTenantsQuery = $scope;
        }
    }

    protected function getOptions()
    {
        return array_merge([
            new InputOption('tenants', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, 'The tenants to run this command for. Leave empty for all tenants', null),
            new InputOption('skip-tenants', null, InputOption::VALUE_IS_ARRAY|InputOption::VALUE_OPTIONAL, 'The tenants to skip when running this command', null),
            new InputOption('with-pending', null, InputOption::VALUE_OPTIONAL, 'Include pending tenants in query if true/1, exclude if false/0. Defaults to the tenancy.pending.include_in_queries config value.'),
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
        $query = tenancy()->query()
            ->when($tenantKeys, function ($query) use ($tenantKeys) {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $tenantKeys);
            })
            ->when($this->option('tenants'), function ($query) {
                $query->whereIn(tenancy()->model()->getTenantKeyName(), $this->option('tenants'));
            })
            ->when($this->option('skip-tenants'), function ($query) {
                $query->whereNotIn(tenancy()->model()->getTenantKeyName(), $this->option('skip-tenants'));
            })
            ->when(tenancy()->model()::hasGlobalScope(PendingScope::class), function ($query) {
                $includePending = $this->input->hasParameterOption('--with-pending')
                    ? filter_var($this->option('with-pending') ?? true, FILTER_VALIDATE_BOOLEAN)
                    : config('tenancy.pending.include_in_queries');

                $query->withPending($includePending);
            });

        if ($scope = static::$scopeTenantsQuery) {
            $query->where(fn (Builder $query) => $scope($query));
        }

        return $query;
    }

    public function __construct(mixed ...$args)
    {
        parent::__construct(...$args);

        $this->specifyParameters();
    }
}
