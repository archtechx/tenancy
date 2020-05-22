<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Database\Concerns;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Events;

/**
 * @property string|int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property array $data
 *
 * @method TenantCollection all()
 */
class Tenant extends Model implements Contracts\Tenant
{
    use Concerns\CentralConnection,
        Concerns\GeneratesIds,
        Concerns\HasDataColumn;

    protected $table = 'tenants';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey(): ?string
    {
        return $this->getAttribute($this->getTenantKeyName());
    }

    public function newCollection(array $models = []): TenantCollection
    {
        return new TenantCollection($models);
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
        'saving' => Events\SavingTenant::class,
        'created' => Events\TenantCreated::class,
        'creating' => Events\CreatingTenant::class,
        'updated' => Events\TenantUpdated::class,
        'updating' => Events\UpdatingTenant::class,
        'deleted' => Events\TenantDeleted::class,
        'deleting' => Events\DeletingTenant::class,
    ];
}
