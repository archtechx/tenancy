<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Carbon\Carbon;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\CreatingPendingTenant;
use Stancl\Tenancy\Events\PendingTenantCreated;
use Stancl\Tenancy\Events\PendingTenantPulled;
use Stancl\Tenancy\Events\PullingPendingTenant;

// todo consider adding a method that sets pending_since to null — to flag tenants as not-pending

/**
 * @property Carbon $pending_since
 *
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withPending(bool $withPending = true)
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder onlyPending()
 * @method static static|\Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder withoutPending()
 */
trait HasPending
{
    /**
     * Boot the has pending trait for a model.
     *
     * @return void
     */
    public static function bootHasPending()
    {
        static::addGlobalScope(new PendingScope());
    }

    /**
     * Initialize the has pending trait for an instance.
     *
     * @return void
     */
    public function initializeHasPending()
    {
        $this->casts['pending_since'] = 'timestamp';
    }

    /**
     * Determine if the model instance is in a pending state.
     *
     * @return bool
     */
    public function pending()
    {
        return ! is_null($this->pending_since);
    }

    /** Create a pending tenant. */
    public static function createPending($attributes = []): Tenant
    {
        $tenant = static::create($attributes);

        event(new CreatingPendingTenant($tenant));

        // Update the pending_since value only after the tenant is created so it's
        // Not marked as pending until finishing running the migrations, seeders, etc.
        $tenant->update([
            'pending_since' => now()->timestamp,
        ]);

        event(new PendingTenantCreated($tenant));

        return $tenant;
    }

    /** Pull a pending tenant. */
    public static function pullPending(): Tenant
    {
        return static::pullPendingFromPool(true);
    }

    /** Try to pull a tenant from the pool of pending tenants. */
    public static function pullPendingFromPool(bool $firstOrCreate = false): ?Tenant
    {
        if (! static::onlyPending()->exists()) {
            if (! $firstOrCreate) {
                return null;
            }

            static::createPending();
        }

        // A pending tenant is surely available at this point
        $tenant = static::onlyPending()->first();

        event(new PullingPendingTenant($tenant));

        $tenant->update([
            'pending_since' => null,
        ]);

        event(new PendingTenantPulled($tenant));

        return $tenant;
    }
}
