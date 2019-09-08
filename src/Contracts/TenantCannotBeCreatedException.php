<?php

namespace Stancl\Tenancy\Contracts;

abstract class TenantCannotBeCreatedException extends \Exception
{
    abstract function reason(): string;

    private $message;

    public function __construct()
    {
        $this->message = 'Tenant cannot be craeted. Reason: ' . $this->reason();
    }
}