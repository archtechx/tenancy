<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Exceptions;

use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;

class TenantDatabaseAlreadyExistsException extends TenantCannotBeCreatedException
{
    public function __construct(
        protected string $database,
    ) {
        parent::__construct();
    }

    public function reason(): string
    {
        return "Database {$this->database} already exists.";
    }
}
