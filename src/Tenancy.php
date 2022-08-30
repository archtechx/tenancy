<?php

declare(strict_types=1);

namespace Stancl\Tenancy;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Traits\Macroable;
use Stancl\Tenancy\Concerns\Debuggable;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByIdException;

class Tenancy
{
    use Macroable, Debuggable;

    /**
     * The current tenant.
     *
     * @var (Tenant&Model)|null
     */
    public ?Tenant $tenant = null;

    // todo docblock
    public ?Closure $getBootstrappersUsing = null;

    /** Is tenancy fully initialized? */
    public bool $initialized = false; // todo document the difference between $tenant being set and $initialized being true (e.g. end of initialize() method)

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

        // todo0 for phpstan this should be $this->tenant?, but first I want to clean up the $initialized logic and explore removing the property
        if ($this->initialized && $this->tenant->getTenantKey() === $tenant->getTenantKey()) {
            return;
        }

        // TODO: Remove this (so that runForMultiple() is still performant) and make the FS bootstrapper work either way
        if ($this->initialized) {
            $this->end();
        }

        $this->tenant = $tenant;

        event(new Events\InitializingTenancy($this));

        $this->initialized = true;

        event(new Events\TenancyInitialized($this));
    }

    public function end(): void
    {
        if (! $this->initialized) {
            return;
        }

        event(new Events\EndingTenancy($this));

        // todo find a way to refactor these two methods

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
        $class = config('tenancy.tenant_model');

        return new $class;
    }

    public static function find(int|string $id): Tenant|null
    {
        return static::model()->where(static::model()->getTenantKeyName(), $id)->first();
    }

    /**
     * Run a callback in the central context.
     * Atomic, safely reverts to previous context.
     */
    public function central(Closure $callback)
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
     * @param Tenant[]|\Traversable|string[]|null $tenants
     */
    public function runForMultiple($tenants, Closure $callback): void
    {
        // Convert null to all tenants
        $tenants = is_null($tenants) ? $this->model()->cursor() : $tenants;

        // Convert incrementing int ids to strings
        $tenants = is_int($tenants) ? (string) $tenants : $tenants;

        // Wrap string in array
        $tenants = is_string($tenants) ? [$tenants] : $tenants;

        // Use all tenants if $tenants is falsey
        $tenants = $tenants ?: $this->model()->cursor();

        $originalTenant = $this->tenant;

        foreach ($tenants as $tenant) {
            if (! $tenant instanceof Tenant) {
                $tenant = $this->find($tenant);
            }

            $this->initialize($tenant);
            $callback($tenant);
        }

        if ($originalTenant) {
            $this->initialize($originalTenant);
        } else {
            $this->end();
        }
    }
}
