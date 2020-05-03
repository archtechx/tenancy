<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

use Stancl\Tenancy\Tenant;

trait CreatesDatabaseUsers
{
    public function createDatabase(Tenant $tenant): bool
    {
        return $this->database()->transaction(function () use ($tenant) {
            parent::createDatabase($tenant);

            return $this->createUser($tenant->database());
        });
    }

    public function deleteDatabase(Tenant $tenant): bool
    {
        return $this->database()->transaction(function () use ($tenant) {
            parent::deleteDatabase($tenant);

            return $this->deleteUser($tenant->database());
        });
    }
}
