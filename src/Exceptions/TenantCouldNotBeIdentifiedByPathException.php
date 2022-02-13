<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Exceptions;

use Stancl\Tenancy\Contracts\TenantCouldNotBeIdentifiedException;

class TenantCouldNotBeIdentifiedByPathException extends TenantCouldNotBeIdentifiedException
{
    public function __construct($tenant_id)
    {
        parent::__construct("Tenant could not be identified on path with tenant_id: $tenant_id");
    }

    // public function getSolution(): Solution
    // {
    //     return BaseSolution::create('Tenant could not be identified on this path')
    //         ->setSolutionDescription('Did you forget to create a tenant for this path?')
    //         ->setDocumentationLinks([
    //             'Creating Tenants' => 'https://tenancyforlaravel.com/docs/v3/tenants/',
    //         ]);
    // }
}
