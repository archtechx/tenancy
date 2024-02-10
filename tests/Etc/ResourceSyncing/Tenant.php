<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\ResourceSyncing;

use CentralUserWithSoftDeletes;
use Stancl\Tenancy\ResourceSyncing\TenantPivot;
use Stancl\Tenancy\Tests\Etc\Tenant as BaseTenant;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tenant extends BaseTenant
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(CentralUser::class, 'tenant_users', 'tenant_id', 'global_user_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }

    public function customPivotUsers(): BelongsToMany
    {
        return $this->belongsToMany(CentralUser::class, 'tenant_users', 'tenant_id', 'global_user_id', 'id', 'global_id', 'users')
            ->using(CustomPivot::class);
    }

    public function softDeletesUsers(): BelongsToMany
    {
        return $this->belongsToMany(CentralUserWithSoftDeletes::class, 'tenant_users', 'tenant_id', 'global_user_id', 'id', 'global_id', 'users')
            ->using(CustomPivot::class);
    }
}
