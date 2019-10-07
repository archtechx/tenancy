<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class DomainModel extends Model
{
    use CentralConnection;

    protected $guarded = [];
    protected $primaryKey = 'domain';
    public $incrementing = false;
    public $timestamps = false;

    public function getTable()
    {
        return config('tenancy.storage_drivers.db.table_names.DomainModel', 'domains');
    }
}
