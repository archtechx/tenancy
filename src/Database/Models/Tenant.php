<?php

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Contracts;

// todo @property
class Tenant extends Model implements Contracts\TenantWithDatabase
{
    use Concerns\CentralConnection, Concerns\HasADataColumn, Concerns\GeneratesIds, Concerns\HasADataColumn  {
        Concerns\HasADataColumn::getCasts as dataColumnCasts;
    }

    public $primaryKey = 'id';
    public $guarded = [];

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey(): string
    {
        return $this->getAttribute($this->getTenantKeyName());
    }

    public function getCasts()
    {
        return array_merge($this->dataColumnCasts(), [
            'id' => $this->getIncrementing() ? 'integer' : 'string',
        ]);
    }

    public function getIncrementing()
    {
        return config('tenancy.id_generator') === null;
    }

    public static function internalPrefix(): string
    {
        return config('tenancy.internal_prefix');
    }

    /**
     * Get an internal key.
     */
    public function getInternal(string $key)
    {
        return $this->getAttribute(static::internalPrefix() . $key);
    }

    /**
     * Set internal key.
     */
    public function setInternal(string $key, $value)
    {
        $this->setAttribute(static::internalPrefix() . $key, $value);

        return $this;
    }

    public function database(): DatabaseConfig
    {
        return new DatabaseConfig($this);
    }

    public function run(callable $callback)
    {
        $originalTenant = tenant();

        tenancy()->initialize($this);
        $result = $callback($this);

        if ($originalTenant) {
            tenancy()->initialize($originalTenant);
        } else {
            tenancy()->end();
        }

        return $result;
    }

    public $dispatchesEvents = [
        'saved' => Events\TenantSaved::class,
        'created' => Events\TenantCreated::class,
        'updated' => Events\TenantUpdated::class,
        'deleted' => Events\TenantDeleted::class,
    ];
}
