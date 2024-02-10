<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\ResourceSyncing\ResourceSyncing;
use Stancl\Tenancy\ResourceSyncing\Syncable;

class TenantUser extends Model implements Syncable
{
    use ResourceSyncing;

    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;

    public static array $syncedAttributes = [];

    public static array $creationAttributes = [];

    public static bool $shouldSync = true;

    public function shouldSync(): bool
    {
        return static::$shouldSync;
    }

    public function getCentralModelName(): string
    {
        return CentralUser::class;
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
