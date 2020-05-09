<?php

namespace Stancl\Tenancy\Database\Models\Concerns;

trait HasDomains
{
    public function domains()
    {
        return $this->hasMany(config('tenancy.domain_model'));
    }
}