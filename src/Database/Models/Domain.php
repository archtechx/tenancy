<?php

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Events\DomainCreated;
use Stancl\Tenancy\Events\DomainDeleted;
use Stancl\Tenancy\Events\DomainSaved;
use Stancl\Tenancy\Events\DomainUpdated;
use Stancl\Tenancy\Exceptions\DomainOccupiedByOtherTenantException;
use Stancl\Tenancy\Contracts;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $domain
 * @property string $tenant_id
 *
 * @property-read Tenant|Model $tenant
 */
class Domain extends Model implements Contracts\Domain
{
    use CentralConnection;

    protected $guarded = [];

    public static function booted()
    {
        $ensureDomainIsNotOccupied = function (Domain $self) {
            if ($domain = Domain::where('domain', $self->domain)->first()) {
                if ($domain->getKey() !== $self->getKey()) {
                    throw new DomainOccupiedByOtherTenantException($self->domain);
                }
            }
        };

        static::saving($ensureDomainIsNotOccupied);
    }

    public function tenant()
    {
        return $this->belongsTo(config('tenancy.tenant_model'));
    }

    public $dispatchEvents = [
        'saved' => DomainSaved::class,
        'created' => DomainCreated::class,
        'updated' => DomainUpdated::class,
        'deleted' => DomainDeleted::class,
    ];
}
