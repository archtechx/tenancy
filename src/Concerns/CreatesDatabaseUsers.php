<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Stancl\Tenancy\Contracts\TenantWithDatabase;

trait CreatesDatabaseUsers
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->transaction(function () use ($tenant) {
            parent::createDatabase($tenant);

            return $this->createUser($tenant->database());
        });
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        return $this->database()->transaction(function () use ($tenant) {
            parent::deleteDatabase($tenant);

            return $this->deleteUser($tenant->database());
        });
    }
}
