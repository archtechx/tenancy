<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use \Illuminate\Database\Eloquent\Model;

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
     * @return BelongsTo<Tenant&Model, Model>
     */
    public function tenant(): BelongsTo;
}
