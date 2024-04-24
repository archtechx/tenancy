<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

/**
 * Interface indicating that the queries of the model it's used on
 * get scoped using RLS (instead of the global TenantScope).
 *
 * All models whose queries you want to scope using RLS
 * need to implement this interface if RLS scoping is explicit (= when TraitRLSManager::$implicitRLS is false).
 * The models also have to use one of the single-database traits.
 *
 * Used with Postgres RLS via TraitRLSManager.
 *
 * @see \Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager
 * @see BelongsToTenant
 * @see BelongsToPrimaryModel
 */
interface RLSModel {}
