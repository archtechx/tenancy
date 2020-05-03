<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

/**
 * Used by sqlite to wrap database name in database_path().
 */
interface ModifiesDatabaseNameForConnection
{
    public function getDatabaseNameForConnection(string $original): string;
}
