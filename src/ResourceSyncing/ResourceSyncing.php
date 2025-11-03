<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\ResourceSyncing\Events\CentralResourceAttachedToTenant;
use Stancl\Tenancy\ResourceSyncing\Events\CentralResourceDetachedFromTenant;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceDeleted;
use Stancl\Tenancy\ResourceSyncing\Events\SyncedResourceSaved;
use Stancl\Tenancy\ResourceSyncing\Events\SyncMasterDeleted;
use Stancl\Tenancy\ResourceSyncing\Events\SyncMasterRestored;

trait ResourceSyncing
{
    public static function bootResourceSyncing(): void
    {
        static::saved(function (Syncable&Model $model) {
            if ($model->shouldSync() && ($model->wasRecentlyCreated || $model->wasChanged($model->getSyncedAttributeNames()))) {
                $model->triggerSyncEvent();
            }
        });

        static::deleted(function (Syncable&Model $model) {
            if ($model->shouldSync()) {
                $model->triggerDeleteEvent();
            }
        });

        static::creating(function (Syncable&Model $model) {
            if (! $model->getAttribute($model->getGlobalIdentifierKeyName()) && app()->bound(UniqueIdentifierGenerator::class)) {
                $model->setAttribute(
                    $model->getGlobalIdentifierKeyName(),
                    app(UniqueIdentifierGenerator::class)->generate($model)
                );
            }
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::forceDeleting(function (Syncable&Model $model) {
                if ($model->shouldSync()) {
                    $model->triggerDeleteEvent(true);
                }
            });

            static::restoring(function (Syncable&Model $model) {
                if ($model instanceof SyncMaster && $model->shouldSync()) {
                    $model->triggerRestoreEvent();
                }
            });
        }
    }

    public function triggerSyncEvent(): void
    {
        /** @var Syncable&Model $this */
        event(new SyncedResourceSaved($this, tenant()));
    }

    public function triggerDeleteEvent(bool $forceDelete = false): void
    {
        if ($this instanceof SyncMaster) {
            /** @var SyncMaster&Model $this */
            event(new SyncMasterDeleted($this, $forceDelete));
        }

        event(new SyncedResourceDeleted($this, tenant(), $forceDelete));
    }

    public function triggerRestoreEvent(): void
    {
        if ($this instanceof SyncMaster && in_array(SoftDeletes::class, class_uses_recursive($this), true)) {
            /** @var SyncMaster&Model $this */
            event(new SyncMasterRestored($this));
        }
    }

    /** Default implementation for \Stancl\Tenancy\ResourceSyncing\SyncMaster */
    public function triggerAttachEvent(TenantWithDatabase&Model $tenant): void
    {
        if ($this instanceof SyncMaster) {
            /** @var SyncMaster&Model $this */
            event(new CentralResourceAttachedToTenant($this, $tenant));
        }
    }

    /** Default implementation for \Stancl\Tenancy\ResourceSyncing\SyncMaster */
    public function triggerDetachEvent(TenantWithDatabase&Model $tenant): void
    {
        if ($this instanceof SyncMaster) {
            /** @var SyncMaster&Model $this */
            event(new CentralResourceDetachedFromTenant($this, $tenant));
        }
    }

    public function getCreationAttributes(): array
    {
        return $this->getSyncedAttributeNames();
    }

    public function shouldSync(): bool
    {
        return true;
    }

    public function tenants(): BelongsToMany
    {
        return $this->morphToMany(config('tenancy.models.tenant'), 'tenant_resources', 'tenant_resources', 'resource_global_id', 'tenant_id', $this->getGlobalIdentifierKeyName())
            ->using(TenantMorphPivot::class);
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getGlobalIdentifierKey(): string
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }
}
