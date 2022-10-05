<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Tenant $tenant
 *
 * @see \Stancl\Tenancy\Database\Models\Domain
 *
 * @method __call(string $method, array $parameters) IDE support. This will be a model.
 * @method static __callStatic(string $method, array $parameters) IDE support. This will be a model.
 * @mixin \Illuminate\Database\Eloquent\Model
 */
interface Domain
{
    public function tenant(): BelongsTo;
}
