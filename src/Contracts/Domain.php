<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Tenant $tenant
 *
 * @see \Stancl\Tenancy\Database\Models\Domain
 *
 * @mixin Model
 */
interface Domain
{
    /**
     * @return BelongsTo<Tenant&Model, $this&Model>
     */
    public function tenant(): BelongsTo;
}
