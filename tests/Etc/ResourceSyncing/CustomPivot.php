<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\ResourceSyncing\PivotWithRelation;
use Stancl\Tenancy\ResourceSyncing\TenantPivot;

class CustomPivot extends TenantPivot implements PivotWithRelation
{
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(CentralUser::class);
    }

    public function getRelatedModel(): Model
    {
        return $this->users()->getModel();
    }
}
