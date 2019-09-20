<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\DatabaseManager;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class DomainModel extends Model
{
    protected $guarded = [];
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    public function getTable()
    {
        return config('tenancy.storage.db.table_names.DomainModel', 'domains');
    }

    public function getConnectionName()
    {
        return config('tenancy.storage.db.connection') ?? app(DatabaseManager::class)->originalDefaultConnectionName;
    }
}
