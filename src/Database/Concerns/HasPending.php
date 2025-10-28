<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\CreatingPendingTenant;
use Stancl\Tenancy\Events\PendingTenantCreated;
use Stancl\Tenancy\Events\PendingTenantPulled;
use Stancl\Tenancy\Events\PullingPendingTenant;

/**
 * @property ?Carbon $pending_since
 *
 * @method static static|\Illuminate\Database\Eloquent\Builder<static>|\Illuminate\Database\Query\Builder withPending(bool $withPending = true)
 * @method static static|\Illuminate\Database\Eloquent\Builder<static>|\Illuminate\Database\Query\Builder onlyPending()
 * @method static static|\Illuminate\Database\Eloquent\Builder<static>|\Illuminate\Database\Query\Builder withoutPending()
 */
trait HasPending
{
    public static string $pendingSinceCast = 'timestamp';

    /** Boot the trait. */
    public static function bootHasPending(): void
    {
        static::addGlobalScope(new PendingScope());
    }

    /** Initialize the trait. */
    public function initializeHasPending(): void
    {
        $this->casts['pending_since'] = static::$pendingSinceCast;
    }

    /** Determine if the model instance is in a pending state. */
    public function pending(): bool
    {
        return ! is_null($this->pending_since);
    }

    /**
     * Create a pending tenant.
     *
     * @param array<string, mixed> $attributes
     */
    public static function createPending(array $attributes = []): Model&Tenant
    {
        try {
            $tenant = static::create($attributes);
            event(new CreatingPendingTenant($tenant));
        } finally {
            // Update the pending_since value only after the tenant is created so it's
            // not marked as pending until after migrations, seeders, etc are run.
            $tenant->update([
                'pending_since' => now()->timestamp,
            ]);
        }

        event(new PendingTenantCreated($tenant));

        return $tenant;
    }

    /**
     * Pull a pending tenant from the pool or create a new one if the pool is empty.
     *
     * @param array $attributes The attributes to set on the tenant.
     */
    public static function pullPending(array $attributes = []): Model&Tenant
    {
        /** @var Model&Tenant $pendingTenant */
        $pendingTenant = static::pullPendingFromPool(true, $attributes);

        return $pendingTenant;
    }

    /**
     * Try to pull a tenant from the pool of pending tenants.
     *
     * @param bool $firstOrCreate If true, a tenant will be *created* if the pool is empty. Otherwise null is returned.
     * @param array $attributes The attributes to set on the tenant.
     */
    public static function pullPendingFromPool(bool $firstOrCreate = false, array $attributes = []): ?Tenant
    {
        $tenant = DB::transaction(function () use ($attributes): ?Tenant {
            /** @var (Model&Tenant)|null $tenant */
            $tenant = static::onlyPending()->first();

            if ($tenant !== null) {
                event(new PullingPendingTenant($tenant));
                $tenant->update(array_merge($attributes, [
                    'pending_since' => null,
                ]));
            }

            return $tenant;
        });

        if ($tenant === null) {
            return $firstOrCreate ? static::create($attributes) : null;
        }

        // Only triggered if a tenant that was pulled from the pool is returned
        event(new PendingTenantPulled($tenant));

        return $tenant;
    }
}
