<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;

class TenantDatabaseUserAlreadyExistsException extends TenantCannotBeCreatedException
{
    public function __construct(
        protected string $user,
    ) {
        parent::__construct();
    }

    public function reason(): string
    {
        return "Database user {$this->user} already exists.";
    }
}
