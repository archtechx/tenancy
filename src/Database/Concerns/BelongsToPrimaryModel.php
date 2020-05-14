<?php

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Database\ParentModelScope;

trait BelongsToPrimaryModel
{
    abstract public function getParentRelationshipName(): string;

    public static function bootBelongsToPrimaryModel()
    {
        static::addGlobalScope(new ParentModelScope);
    }
}
