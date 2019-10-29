<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Config\Repository as ConfigRepository;
use Stancl\Tenancy\Tenant;
use stdClass;

class TenantRepository extends Repository
{
    public function all($ids = [])
    {
        if ($ids) {
            $data = $this->whereIn('id', $ids)->get();
        } else {
            $data = $this->table()->get();
        }

        return $data->map(function (stdClass $obj) {
            return $this->decodeData((array) $obj);
        });
    }

    public function find($tenant)
    {
        return (array) $this->table()->find(
            $tenant instanceof Tenant ? $tenant->id : $tenant
        );
    }

    public function findBy(string $key, $value)
    {
        if (in_array($key, static::customColumns())) {
            return (array) $this->table()->where($key, $value)->first();
        }

        return (array) $this->table()->where(
            static::dataColumn() . '->' . $key,
            $value
        )->first();
    }

    public function updateTenant(Tenant $tenant)
    {
        $this->putMany($tenant->data, $tenant);
    }

    public function exists(Tenant $tenant)
    {
        return $this->where('id', $tenant->id)->exists();
    }

    public function get(string $key, Tenant $tenant)
    {
        return $this->decodeFreshDataForTenant($tenant)[$key] ?? null;
    }

    public function getMany(array $keys, Tenant $tenant)
    {
        $decodedData = $this->decodeFreshDataForTenant($tenant);

        $result = [];

        foreach ($keys as $key) {
            $result[$key] = $decodedData[$key] ?? null;
        }

        return $result;
    }

    public function put(string $key, $value, Tenant $tenant)
    {
        $record = $this->where('id', $tenant->id);

        if (in_array($key, static::customColumns())) {
            $record->update([$key => $value]);
        } else {
            $data = json_decode($record->first()->{static::dataColumn()}, true);
            $data[$key] = $value;

            $record->update([static::dataColumn() => $data]);
        }
    }

    public function putMany(array $kvPairs, Tenant $tenant)
    {
        $record = $this->where('id', $tenant->id);

        $data = [];
        $jsonData = json_decode($record->first()->{static::dataColumn()}, true);
        foreach ($kvPairs as $key => $value) {
            if (in_array($key, static::customColumns())) {
                $data[$key] = $value;
                continue;
            } else {
                $jsonData[$key] = $value;
            }
        }

        $data[static::dataColumn()] = json_encode($jsonData);

        $record->update($data);
    }

    public function deleteMany(array $keys, Tenant $tenant)
    {
        $record = $this->where('id', $tenant->id);

        $data = [];
        $jsonData = json_decode($record->first(static::dataColumn())->data, true);
        foreach ($keys as $key) {
            if (in_array($key, static::customColumns())) {
                $data[$key] = null;

                continue;
            } else {
                unset($jsonData[$key]);
            }
        }

        $data[static::dataColumn()] = json_encode($jsonData);

        $record->update($data);
    }

    public function decodeFreshDataForTenant(Tenant $tenant): array
    {
        return $this->decodeData(
            (array) $this->table()->where('id', $tenant->id)->first()
        );
    }

    public static function decodeData(array $columns): array
    {
        $dataColumn = static::dataColumn();
        $decoded = json_decode($columns[$dataColumn], true);
        $columns = array_merge($columns, $decoded);

        // If $columns[$dataColumn] has been overriden by a value, don't delete the key.
        if (! array_key_exists($dataColumn, $decoded)) {
            unset($columns[$dataColumn]);
        }

        return $columns;
    }

    public function insert(Tenant $tenant)
    {
        $this->table()->insert(array_merge(
            $this->encodeData($tenant->data),
            ['id' => $tenant->id]
        ));
    }

    public static function encodeData(array $data): array
    {
        $result = [];
        foreach (array_intersect(static::customColumns(), array_keys($data)) as $customColumn) {
            $result[$customColumn] = $data[$customColumn];
            unset($data[$customColumn]);
        }

        $result = array_merge($result, [static::dataColumn() => json_encode($data)]);

        return $result;
    }

    public static function customColumns(): array
    {
        return config('tenancy.storage_drivers.db.custom_columns', []);
    }

    public static function dataColumn(): string
    {
        return config('tenancy.storage_drivers.db.data_column', 'data');
    }

    public function getTable(ConfigRepository $config)
    {
        return $config->get('tenancy.storage_drivers.db.table_names.TenantModel') // legacy
            ?? $config->get('tenancy.storage_drivers.db.table_names.tenants')
            ?? 'tenants';
    }
}
