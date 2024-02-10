<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Tenant $tenant
 *
 * @see \Stancl\Tenancy\Database\Models\Domain
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
interface Domain
{
    public function tenant(): BelongsTo;
}
