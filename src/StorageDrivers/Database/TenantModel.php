<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Database\Eloquent\Model;

/**
 * @internal Class is subject to breaking changes in minor and patch versions.
 */
class TenantModel extends Model
{
    use CentralConnection;

    protected $guarded = [];
    protected $primaryKey = 'id';
    public $incrementing = false;
    public $timestamps = false;

    public function getTable()
    {
        return config('tenancy.storage_drivers.db.table_names.TenantModel', 'tenants');
    }

    public static function dataColumn()
    {
        return config('tenancy.storage_drivers.db.data_column', 'data');
    }

    public static function customColumns()
    {
        return config('tenancy.storage_drivers.db.custom_columns', []);
    }

    public static function encodeData(array $data)
    {
        $result = [];
        $jsonData = [];

        foreach ($data as $key => $value) {
            if (in_array($key, static::customColumns(), true)) {
                $result[$key] = $value;
            } else {
                $jsonData[$key] = $value;
            }
        }

        $result['data'] = $jsonData ? json_encode($jsonData) : '{}';

        return $result;
    }

    public static function getAllTenants(array $ids)
    {
        $tenants = $ids ? static::findMany($ids) : static::all();

        return $tenants->map([__CLASS__, 'decodeData'])->toBase();
    }

    public function decoded()
    {
        return static::decodeData($this);
    }

    /**
     * Return a tenant array with data decoded into separate keys.
     *
     * @param self|array $tenant
     * @return array
     */
    public static function decodeData($tenant)
    {
        $tenant = $tenant instanceof self ? (array) $tenant->attributes : $tenant;
        $decoded = json_decode($tenant[$dataColumn = static::dataColumn()], true);

        foreach ($decoded as $key => $value) {
            $tenant[$key] = $value;
        }

        // If $tenant[$dataColumn] has been overriden by a value, don't delete the key.
        if (! array_key_exists($dataColumn, $decoded)) {
            unset($tenant[$dataColumn]);
        }

        return $tenant;
    }

    public function getFromData(string $key)
    {
        $this->dataArray = $this->dataArray ?? json_decode($this->{$this->dataColumn()}, true);

        return $this->dataArray[$key] ?? null;
    }

    public function get(string $key)
    {
        return $this->attributes[$key] ?? $this->getFromData($key) ?? null;
    }

    public function getMany(array $keys): array
    {
        return array_reduce($keys, function ($result, $key) {
            $result[$key] = $this->get($key);

            return $result;
        }, []);
    }

    public function put(string $key, $value)
    {
        if (in_array($key, $this->customColumns())) {
            $this->update([$key => $value]);
        } else {
            $obj = json_decode($this->{$this->dataColumn()});
            $obj->$key = $value;

            $this->update([$this->dataColumn() => json_encode($obj)]);
        }

        return $value;
    }

    public function putMany(array $kvPairs)
    {
        $customColumns = [];
        $jsonObj = json_decode($this->{$this->dataColumn()});

        foreach ($kvPairs as $key => $value) {
            if (in_array($key, $this->customColumns())) {
                $customColumns[$key] = $value;
                continue;
            }

            $jsonObj->$key = $value;
        }

        $this->update(array_merge($customColumns, [
            $this->dataColumn() => json_encode($jsonObj),
        ]));
    }
}
