<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Traits\Macroable;
use Stancl\Tenancy\Concerns\DealsWithRouteContexts;
use Stancl\Tenancy\Concerns\ManagesRLSPolicies;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByIdException;

class Tenancy
{
    use Macroable, DealsWithRouteContexts, ManagesRLSPolicies;

    /**
     * The current tenant.
     */
    public Tenant|null $tenant = null;

    /**
     * Custom callback for providing a list of bootstrappers to use.
     * When this is null, config('tenancy.bootstrappers') is used.
     * @var ?Closure(): list<TenancyBootstrapper>
     */
    public ?Closure $getBootstrappersUsing = null;

    /** Is tenancy fully initialized? */
    public bool $initialized = false;

    /**
     * List of relations to eager load when fetching a tenant via tenancy()->find().
     */
    public static array $findWith = [];

    /**
     * List of bootstrappers that have been initialized.
     *
     * This is used when reverting tenancy, mainly if an exception
     * occurs during bootstrapping, to ensure we don't revert
     * bootstrappers that haven't been properly initialized
     * (bootstrapped for the first time) previously.
     *
     * @internal
     *
     * @var list<class-string<TenancyBootstrapper>>
     */
    public array $initializedBootstrappers = [];

    /**
     * List of features that have been bootstrapped.
     *
     * Since features may be bootstrapped multiple times during
     * the request cycle (in TSP::boot() and any other times the user calls
     * bootstrapFeatures()), we keep track of which features have already
     * been bootstrapped so we do not bootstrap them again. Features are
     * bootstrapped once and irreversible.
     *
     * The main point of this is that some features *need* to be bootstrapped
     * very early (see #949), so we bootstrap them directly in TSP, but we
     * also need the ability to *change* which features are used at runtime
     * (mainly tests of this package) and bootstrap features again after making
     * changes to config('tenancy.features').
     */
    protected array $bootstrappedFeatures = [];

    /** Initialize tenancy for the passed tenant. */
    public function initialize(Tenant|int|string $tenant): void
    {
        if (! $tenant instanceof Tenant) {
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
     * @return (T is PendingDispatch ? null : T)
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $originalTenant = $this->tenant;
        $result = null;

        try {
            $this->initialize($tenant);
            $result = $callback($tenant);
        } finally {
            if ($result instanceof PendingDispatch) { // #1277
                $result = null;
            }

            if ($originalTenant) {
                $this->initialize($originalTenant);
            } else {
                $this->end();
            }
        }

        return $result;
    }

    public function end(): void
    {
        if (! $this->initialized) {
            return;
        }

        // We fire both of these events before unsetting tenant so that listeners
        // to both events can access the current tenant. Having separate events
        // still has value as it's consistent with our other events and provides
        // more granularity for event listeners, e.g. for ensuring something runs
        // before standard TenancyEnded listeners such as RevertToCentralContext.
        event(new Events\EndingTenancy($this));
        event(new Events\TenancyEnded($this));

        $this->tenant = null;

        $this->initialized = false;
    }

    /** @return TenancyBootstrapper[] */
    public function getBootstrappers(): array
    {
        // If no callback for getting bootstrappers is set, we return the ones in config.
        $resolve = $this->getBootstrappersUsing ?? function (?Tenant $tenant) {
            return config('tenancy.bootstrappers');
        };

        // Here we instantiate the bootstrappers and return them.
        return array_map('app', $resolve($this->tenant));
    }

    /**
     * Check if a bootstrapper is being used.
     *
     * @param class-string<TenancyBootstrapper> $bootstrapper
     */
    public function usingBootstrapper(string $bootstrapper): bool
    {
        return in_array($bootstrapper, static::getBootstrappers(), true);
    }

    /**
     * Bootstrap configured Tenancy features.
     *
     * Normally, features are bootstrapped directly in TSP::boot(). However, if
     * new features are enabled at runtime (e.g. during tests), this method may
     * be called to bootstrap new features. It's idempotent and keeps track of
     * which features have already been bootstrapped. Keep in mind that feature
     * bootstrapping is irreversible.
     */
    public function bootstrapFeatures(): void
    {
        foreach (config('tenancy.features') ?? [] as $feature) {
            /** @var class-string<Feature> $feature */
            if (! in_array($feature, $this->bootstrappedFeatures)) {
                app($feature)->bootstrap();
                $this->bootstrappedFeatures[] = $feature;
            }
        }
    }

    /**
     * @return Builder<Tenant&Model>
     */
    public static function query(): Builder
    {
        return static::model()->query();
    }

    public static function model(): Tenant&Model
    {
        /** @var class-string<Tenant&Model> $class */
        $class = config('tenancy.models.tenant');

        return new $class;
    }

    /** Name of the column used to relate models to tenants. */
    public static function tenantKeyColumn(): string
    {
        return config('tenancy.models.tenant_key_column') ?? 'tenant_id';
    }

    /**
     * Try to find a tenant using an ID.
     */
    public static function find(int|string $id, ?string $column = null, bool $withRelations = false): (Tenant&Model)|null
    {
        /** @var (Tenant&Model)|null $tenant */
        $tenant = static::model()->with($withRelations ? static::$findWith : [])->firstWhere($column ?? static::model()->getTenantKeyName(), $id);

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

        // If $tenants is falsy by this point (e.g. an empty array) there's no work to be done
        if (! $tenants) {
            return;
        }

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

    public static function invalidateResolverCache(Tenant&Model $tenant): void
    {
        foreach (static::cachedResolvers() as $resolver) {
            /** @var Resolvers\Contracts\CachedTenantResolver $resolver */
            $resolver = app($resolver);

            $resolver->invalidateCache($tenant);
        }
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
