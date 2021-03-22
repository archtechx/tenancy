<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Facade\IgnitionContracts\BaseSolution;
use Facade\IgnitionContracts\ProvidesSolution;
use Facade\IgnitionContracts\Solution;
use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByRequestOriginException extends TenantCouldNotBeIdentifiedException implements ProvidesSolution
{
    public function __construct($tenant_id)
    {
        parent::__construct("Tenant could not be identified by request origin with payload: $tenant_id");
    }

    public function getSolution(): Solution
    {
        return BaseSolution::create('Tenant could not be identified with this request origin')
            ->setSolutionDescription('The request needs to originate from the same domain as the tenant.');
    }
}
