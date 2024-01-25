<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

/**
 * Interface indicating that the queries of the model it's used on
 * get scoped using RLS instead of the global TenantScope.
 *
 * Used with Postgres RLS (single-database tenancy).
 *
 * @see BelongsToTenant
 */
interface RLSModel
{
}
