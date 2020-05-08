<?php

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events;

// todo use a contract
class Tenant extends Model
{
    use Concerns\CentralConnection, Concerns\HasADataColumn, Concerns\GeneratesIds, Concerns\HasADataColumn {
        Concerns\HasADataColumn::getCasts as dataColumnCasts;
    }

    public $primaryKey = 'id';

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

    public $guarded = [];

    public function domains() // todo not required
    {
        return $this->hasMany(Domain::class);
    }

    public static function internalPrefix(): string
    {
        return config('tenancy.database_prefix');
    }

    /**
     * Get an internal key.
     *
     * @param string $key
     * @return mixed
     */
    public function getInternal(string $key)
    {
        return $this->getAttribute(static::internalPrefix() . $key);
    }

    /**
     * Set internal key.
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setInternal(string $key, $value)
    {
        $this->setAttribute($key, $value);

        return $this;
    }

    public function database(): DatabaseConfig
    {
        return new DatabaseConfig($this);
    }

    public function run(callable $callback)
    {
        $originalTenant = $this->manager->getTenant();

        $this->manager->initializeTenancy($this);
        $result = $callback($this);
        $this->manager->endTenancy($this);

        if ($originalTenant) {
            $this->manager->initializeTenancy($originalTenant);
        }

        return $result;
    }

    protected $dispatchesEvents = [
        'saved' => Events\TenantSaved::class,
        'created' => Events\TenantCreated::class,
        'updated' => Events\TenantUpdated::class,
        'deleted' => Events\TenantDeleted::class,
    ];
}
