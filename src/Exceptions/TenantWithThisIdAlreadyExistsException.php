<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCannotBeCreatedException;

class TenantWithThisIdAlreadyExistsException extends TenantCannotBeCreatedException
{
    /** @var string */
    protected $id;

    public function reason(): string
    {
        return "Tenant with id {$this->id} already exists.";
    }

    public function __construct(string $id)
    {
        parent::__construct();

        $this->id = $id;
    }
}
