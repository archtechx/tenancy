<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Database\Concerns;
use Stancl\Tenancy\Database\TenantCollection;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Exceptions\TenancyNotInitializedException;
use Stancl\VirtualColumn\VirtualColumn;

/**
 * @property string|int $id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property array $data
 *
 * @method static TenantCollection all($columns = ['*'])
 */
class Tenant extends Model implements Contracts\Tenant
{
    use VirtualColumn,
        Concerns\CentralConnection,
        Concerns\GeneratesIds,
        Concerns\HasInternalKeys,
        Concerns\TenantRun,
        Concerns\InitializationHelpers,
        Concerns\InvalidatesResolverCache;

    protected static $modelsShouldPreventAccessingMissingAttributes = false;

    protected $table = 'tenants';
    protected $primaryKey = 'id';
    protected $guarded = [];

    public function getTenantKeyName(): string
    {
        return 'id';
    }

    public function getTenantKey(): int|string
    {
        return $this->getAttribute($this->getTenantKeyName());
    }

    /** Get the current tenant. */
    public static function current(): static|null
    {
        return tenant();
    }

    /**
     * Get the current tenant or throw an exception if tenancy is not initialized.
     *
     * @throws TenancyNotInitializedException
     */
    public static function currentOrFail(): static
    {
        return static::current() ?? throw new TenancyNotInitializedException;
    }

    /**
     * @param array<self> $models
     * @return TenantCollection<int|string, self>
     */
    public function newCollection(array $models = []): TenantCollection
    {
        return new TenantCollection($models);
    }

    protected $dispatchesEvents = [
        'saving' => Events\SavingTenant::class,
        'saved' => Events\TenantSaved::class,
        'creating' => Events\CreatingTenant::class,
        'created' => Events\TenantCreated::class,
        'updating' => Events\UpdatingTenant::class,
        'updated' => Events\TenantUpdated::class,
        'deleting' => Events\DeletingTenant::class,
        'deleted' => Events\TenantDeleted::class,
    ];
}
