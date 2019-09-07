<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

class NoTenantIdentifiedException extends Exception
{
    protected $message = 'No tenant has been identified yet.';
}
