<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use Stancl\Tenancy\Concerns\DealsWithRouteContexts;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByIdException;

class Tenancy
{
    use Macroable, DealsWithRouteContexts;

    /**
     * The current tenant.
     */
    public Tenant|null $tenant = null;

    // todo@docblock
    public ?Closure $getBootstrappersUsing = null;

    /** Is tenancy fully initialized? */
    public bool $initialized = false; // todo@docs document the difference between $tenant being set and $initialized being true (e.g. end of initialize() method)

    /** Initialize tenancy for the passed tenant. */
    public function initialize(Tenant|int|string $tenant): void
    {
        if (! is_object($tenant)) {
            $tenantId = $tenant;
            $tenant = $this->find($tenantId);

            if (! $tenant) {
                throw new TenantCouldNotBeIdentifiedByIdException($tenantId);
            }
        }

        if ($this->initialized && $this->tenant?->getTenantKey() === $tenant->getTenantKey()) {
            return;
        }

        if ($this->initialized) {
            $this->end();
        }

        /** @var Tenant&Model $tenant */
        $this->tenant = $tenant;

        event(new Events\InitializingTenancy($this));

        $this->initialized = true;

        event(new Events\TenancyInitialized($this));
    }

    /**
     * Run a callback in the current tenant's context.
     *
     * This method is atomic and safely reverts to the previous context.
     *
     * @template T
     * @param Closure(Tenant): T $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $originalTenant = $this->tenant;

        $this->initialize($tenant);
        $result = $callback($tenant);

        if ($originalTenant) {
            $this->initialize($originalTenant);
        } else {
            $this->end();
        }

        return $result;
    }

    public function end(): void
    {
        if (! $this->initialized) {
            return;
        }

        event(new Events\EndingTenancy($this));

        // todo@samuel find a way to refactor these two methods

        event(new Events\TenancyEnded($this));

        $this->tenant = null;

        $this->initialized = false;
    }

    /** @return TenancyBootstrapper[] */
    public function getBootstrappers(): array
    {
        // If no callback for getting bootstrappers is set, we just return all of them.
        $resolve = $this->getBootstrappersUsing ?? function (Tenant $tenant) {
            return config('tenancy.bootstrappers');
        };

        // Here We instantiate the bootstrappers and return them.
        return array_map('app', $resolve($this->tenant));
    }

    public static function query(): Builder
    {
        return static::model()->query();
    }

    public static function model(): Tenant&Model
    {
        /** @var class-string<Tenant&Model> $class */
        $class = config('tenancy.models.tenant');

        $model = new $class;

        return $model;
    }

    /** Name of the column used to relate models to tenants. */
    public static function tenantKeyColumn(): string
    {
        return config('tenancy.models.tenant_key_column') ?? 'tenant_id';
    }

    /**
     * Try to find a tenant using an ID.
     */
    public static function find(int|string $id, string $column = null): (Tenant&Model)|null
    {
        /** @var (Tenant&Model)|null $tenant */
        $tenant = static::model()->firstWhere($column ?? static::model()->getTenantKeyName(), $id);

        return $tenant;
    }

    /**
     * Run a callback in the central context.
     * Atomic, safely reverts to previous context.
     */
    public function central(Closure $callback): mixed
    {
        $previousTenant = $this->tenant;

        $this->end();

        // This callback will usually not accept arguments, but the previous
        // Tenant is the only value that can be useful here, so we pass that.
        $result = $callback($previousTenant);

        if ($previousTenant) {
            $this->initialize($previousTenant);
        }

        return $result;
    }

    /**
     * Run a callback for multiple tenants.
     * More performant than running $tenant->run() one by one.
     *
     * @param array<Tenant>|array<string|int>|\Traversable|string|int|null $tenants
     */
    public function runForMultiple($tenants, Closure $callback): void
    {
        // Convert null to all tenants
        $tenants = is_null($tenants) ? $this->model()->cursor() : $tenants;

        // Convert incrementing int ids to strings
        $tenants = is_int($tenants) ? (string) $tenants : $tenants;

        // Wrap string in array
        $tenants = is_string($tenants) ? [$tenants] : $tenants;

        // Use all tenants if $tenants is falsy
        $tenants = $tenants ?: $this->model()->cursor(); // todo@phpstan phpstan thinks this isn't needed, but tests fail without it

        $originalTenant = $this->tenant;

        foreach ($tenants as $tenant) {
            if (! $tenant instanceof Tenant) {
                $tenant = $this->find($tenant);
            }

            /** @var Tenant $tenant */
            $this->initialize($tenant);
            $callback($tenant);
        }

        if ($originalTenant) {
            $this->initialize($originalTenant);
        } else {
            $this->end();
        }
    }

    /**
     * Cached tenant resolvers used by the package.
     *
     * @return array<class-string<Resolvers\Contracts\CachedTenantResolver>>
     */
    public static function cachedResolvers(): array
    {
        $resolvers = config('tenancy.identification.resolvers', []);

        $cachedResolvers = array_filter($resolvers, function (array $options) {
            // Resolvers based on CachedTenantResolver have the 'cache' option in the resolver config
            return isset($options['cache']);
        });

        return array_keys($cachedResolvers);
    }

    /**
     * Tenant identification middleware used by the package.
     *
     * @return array<class-string<Middleware\IdentificationMiddleware>>
     */
    public static function middleware(): array
    {
        return config('tenancy.identification.middleware', []);
    }

    /**
     * Default tenant identification middleware used by the package.
     *
     * @return class-string<Middleware\IdentificationMiddleware>
     */
    public static function defaultMiddleware(): string
    {
        return config('tenancy.identification.default_middleware', Middleware\InitializeTenancyByDomain::class);
    }
}
