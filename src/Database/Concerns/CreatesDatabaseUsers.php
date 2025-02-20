<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

trait CreatesDatabaseUsers
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        // todo0 only continue if this returns true, same below
        parent::createDatabase($tenant);

        return $this->createUser($tenant->database());
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        // Some DB engines require the user to be deleted before the database (e.g. Postgres)
        $this->deleteUser($tenant->database());

        return parent::deleteDatabase($tenant);
    }
}
