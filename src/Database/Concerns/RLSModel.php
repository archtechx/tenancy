<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

/**
 * Interface indicating that the queries of the model it's used on
 * get scoped only using RLS (without the global scope).
 *
 * All models whose queries you want to scope only using RLS
 * need to implement this interface if RLS is explicit (= when TraitRLSManager::$implicitRLS is false).
 * The models also have to use one of the single-database traits.
 *
 * Used with Postgres RLS via TraitRLSManager.
 *
 * This only controls whether the global scopes are used. Models discovered
 * by the trait manager and not excluded are always scoped with RLS regardless.
 *
 * @see \Stancl\Tenancy\RLS\PolicyManagers\TraitRLSManager
 * @see BelongsToTenant
 * @see BelongsToPrimaryModel
 */
interface RLSModel {}
