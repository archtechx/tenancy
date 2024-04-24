<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\TenantScope;
use Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager;
use Stancl\Tenancy\Tenancy;

/**
 * @property-read Tenant $tenant
 */
trait BelongsToTenant
{
    use FillsCurrentTenant;

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), Tenancy::tenantKeyColumn());
    }

    public static function bootBelongsToTenant(): void
    {
        // If TraitRLSManager::$implicitRLS is true or this model implements RLSModel
        // Postgres RLS is used for scoping, so we don't enable the scope used with single-database tenancy.
        $implicitRLS = config('tenancy.rls.manager') === TraitRLSManager::class && TraitRLSManager::$implicitRLS;

        if (! $implicitRLS && ! (new static) instanceof RLSModel) {
            static::addGlobalScope(new TenantScope);
        }
    }
}
