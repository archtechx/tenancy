<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

abstract class TenantCannotBeCreatedException extends \Exception
{
    abstract public function reason(): string;

    private $message;

    public function __construct()
    {
        $this->message = 'Tenant cannot be craeted. Reason: ' . $this->reason();
    }
}
