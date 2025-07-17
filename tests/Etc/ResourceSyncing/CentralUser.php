<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\ResourceSyncing\ResourceSyncing;
use Stancl\Tenancy\ResourceSyncing\SyncMaster;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Stancl\Tenancy\ResourceSyncing\TenantMorphPivot;

class CentralUser extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    public $table = 'users';

    public static array $syncedAttributes = [];

    public static array $creationAttributes = [];

    public static bool $shouldSync = true;

    public function getTenantModelName(): string
    {
        return TenantUser::class;
    }

    public function getTenantsRelationshipName(): string
    {
        return 'tenants';
    }


    public function tenants(): BelongsToMany
    {
        return $this->morphToMany(config('tenancy.models.tenant'), 'tenant_resources', 'tenant_resources', 'resource_global_id', 'tenant_id', $this->getGlobalIdentifierKeyName())
            ->using(TenantMorphPivot::class);
    }


    public function shouldSync(): bool
    {
        return static::$shouldSync;
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return static::$syncedAttributes;
    }

    public function getCreationAttributes(): array
    {
        return count(static::$creationAttributes) ? static::$creationAttributes : $this->getSyncedAttributeNames();
    }
}
