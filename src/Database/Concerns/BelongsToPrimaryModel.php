<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Database\ParentModelScope;
use Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager;

trait BelongsToPrimaryModel
{
    abstract public function getRelationshipToPrimaryModel(): string;

    public static function bootBelongsToPrimaryModel(): void
    {
        $implicitRLS = config('tenancy.rls.manager') === TraitRLSManager::class && TraitRLSManager::$implicitRLS;

        if (! $implicitRLS && ! (new static) instanceof RLSModel) {
            static::addGlobalScope(new ParentModelScope);
        }
    }
}
