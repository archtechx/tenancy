<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Concerns;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Tenancy;

/**
 * @property string $domain
 * @property string $tenant_id
 *
 * @property-read Tenant|Model $tenant
 */
class Domain extends Model implements Contracts\Domain
{
    use Concerns\CentralConnection,
        Concerns\EnsuresDomainIsNotOccupied,
        Concerns\ConvertsDomainsToLowercase,
        Concerns\InvalidatesTenantsResolverCache;

    protected $guarded = [];

    /**
     * @return BelongsTo<Tenant&Model, $this>
     */
    public function tenant(): BelongsTo
    {
        /** @var class-string<Tenant&Model> $tenantModel */
        $tenantModel = config('tenancy.models.tenant');

        /** @var BelongsTo<Model&Tenant, $this> */
        return $this->belongsTo($tenantModel, Tenancy::tenantKeyColumn());
    }

    protected $dispatchesEvents = [
        'saving' => Events\SavingDomain::class,
        'saved' => Events\DomainSaved::class,
        'creating' => Events\CreatingDomain::class,
        'created' => Events\DomainCreated::class,
        'updating' => Events\UpdatingDomain::class,
        'updated' => Events\DomainUpdated::class,
        'deleting' => Events\DeletingDomain::class,
        'deleted' => Events\DomainDeleted::class,
    ];
}
