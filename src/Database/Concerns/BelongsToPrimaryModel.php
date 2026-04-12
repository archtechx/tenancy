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
        if (method_exists(static::class, 'whenBooted')) {
            // Laravel 13
            // For context see https://github.com/calebporzio/sushi/commit/62ff7f432cac736cb1da9f46d8f471cb78914b92
            static::whenBooted(fn () => static::configureBelongsToPrimaryModelScope());
        } else {
            static::configureBelongsToPrimaryModelScope();
        }
    }

    protected static function configureBelongsToPrimaryModelScope()
    {
        $implicitRLS = config('tenancy.rls.manager') === TraitRLSManager::class && TraitRLSManager::$implicitRLS;

        if (! $implicitRLS && ! (new static) instanceof RLSModel) {
            static::addGlobalScope(new ParentModelScope);
        }
    }
}
