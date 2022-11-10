<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Contracts\Domain;
use Stancl\Tenancy\Tenancy;

/**
 * @property-read Domain[]|\Illuminate\Database\Eloquent\Collection $domains
 * @mixin \Illuminate\Database\Eloquent\Model
 * @mixin \Stancl\Tenancy\Contracts\Tenant
 */
trait HasDomains
{
    public function domains()
    {
        return $this->hasMany(config('tenancy.models.domain'), Tenancy::tenantKeyColumn());
    }

    public function createDomain($data): Domain
    {
        $class = config('tenancy.models.domain');

        if (! is_array($data)) {
            $data = ['domain' => $data];
        }

        $domain = (new $class)->fill($data);
        $domain->tenant()->associate($this);
        $domain->save();

        return $domain;
    }
}
