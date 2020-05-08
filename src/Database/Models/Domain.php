<?php

namespace Stancl\Tenancy\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Events\DomainCreated;
use Stancl\Tenancy\Events\DomainDeleted;
use Stancl\Tenancy\Events\DomainSaved;
use Stancl\Tenancy\Events\DomainUpdated;

class Domain extends Model
{
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected $dispatchEvents = [
        'saved' => DomainSaved::class,
        'created' => DomainCreated::class,
        'updated' => DomainUpdated::class,
        'deleted' => DomainDeleted::class,
    ];
}
