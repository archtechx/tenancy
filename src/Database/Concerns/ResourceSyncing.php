<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Database\Models\Tenant;
use Stancl\Tenancy\Database\Models\TenantMorphPivot;
use Stancl\Tenancy\Events\SyncedResourceSaved;

trait ResourceSyncing
{
    public static function bootResourceSyncing(): void
    {
        static::saved(function (Syncable $model) {
            if ($model->shouldSync()) {
                $model->triggerSyncEvent();
            }
        });

        static::creating(function (self $model) {
            if (! $model->getAttribute($model->getGlobalIdentifierKeyName()) && app()->bound(UniqueIdentifierGenerator::class)) {
                $model->setAttribute(
                    $model->getGlobalIdentifierKeyName(),
                    app(UniqueIdentifierGenerator::class)->generate($model)
                );
            }
        });
    }

    public function triggerSyncEvent(): void
    {
        /** @var Syncable $this */
        event(new SyncedResourceSaved($this, tenant()));
    }

    public function getSyncedCreationAttributes(): array|null
    {
        return null;
    }

    public function shouldSync(): bool
    {
        return true;
    }

    public function tenants(): MorphToMany
    {
        return $this->morphToMany($this->getResourceTenantModelName(), 'tenant_resources', 'tenant_resources', 'resource_global_id', 'tenant_id', 'global_id')
            ->using(TenantMorphPivot::class);
    }

    public function getResourceTenantModelName(): string // todo better name
    {
        return config('tenancy.tenant_model', Tenant::class);
    }
}
