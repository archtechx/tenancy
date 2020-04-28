<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Stancl\Tenancy\Contracts\Future\CanSetConnection;
use Stancl\Tenancy\Tenant;

interface ManagesDatabaseUsers extends CanSetConnection
{
    public function createDatabaseUser(string $databaseName, Tenant $tenant): void;
}
