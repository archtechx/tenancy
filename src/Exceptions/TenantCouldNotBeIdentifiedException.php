<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

class TenantCouldNotBeIdentifiedException extends \Exception
{
    public function __construct($domain)
    {
        $this->message = "Tenant could not be identified on domain $domain";
    }
}
