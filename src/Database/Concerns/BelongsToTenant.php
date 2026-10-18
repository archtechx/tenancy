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

    /**
     * @return BelongsTo<\Illuminate\Database\Eloquent\Model&Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.models.tenant'), Tenancy::tenantKeyColumn());
    }

    public static function bootBelongsToTenant(): void
    {
        if (method_exists(static::class, 'whenBooted')) {
            // Laravel 13
            // For context see https://github.com/calebporzio/sushi/commit/62ff7f432cac736cb1da9f46d8f471cb78914b92
            static::whenBooted(fn () => static::configureBelongsToTenantScope());
        } else {
            static::configureBelongsToTenantScope();
        }
    }

    protected static function configureBelongsToTenantScope(): void
    {
        // If TraitRLSManager::$implicitRLS is true or this model implements RLSModel
        // Postgres RLS is used for scoping, so we don't enable the scope used with single-database tenancy
        // (unless the model is excluded from TraitRLSManager's discovery).
        $rlsManager = config('tenancy.rls.manager');
        $traitRLS = is_a($rlsManager, TraitRLSManager::class, true);
        $implicitRLS = $traitRLS && $rlsManager::$implicitRLS;

        // Excluded models don't get RLS policies, so they always keep the global scope
        $excluded = $traitRLS && in_array(static::class, $rlsManager::$excludedModels, true);

        if ($excluded || (! $implicitRLS && ! (new static) instanceof RLSModel)) {
            static::addGlobalScope(new TenantScope);
        }
    }
}
