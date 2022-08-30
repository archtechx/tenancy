<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Exception;
use Facade\IgnitionContracts\BaseSolution;
use Facade\IgnitionContracts\ProvidesSolution;
use Facade\IgnitionContracts\Solution;

abstract class TenantCouldNotBeIdentifiedException extends Exception implements ProvidesSolution
{
    /** Default solution title. */
    protected string $solutionTitle = 'Tenant could not be identified';

    /** Default solution description. */
    protected string $solutionDescription = 'Are you sure this tenant exists?';

    /** Set the message. */
    protected function tenantCouldNotBeIdentified(string $how): static
    {
        $this->message = 'Tenant could not be identified ' . $how;

        return $this;
    }

    /** Set the solution title. */
    protected function title(string $solutionTitle): static
    {
        $this->solutionTitle = $solutionTitle;

        return $this;
    }

    /** Set the solution description. */
    protected function description(string $solutionDescription): static
    {
        $this->solutionDescription = $solutionDescription;

        return $this;
    }

    /** Get the Ignition description. */
    public function getSolution(): Solution
    {
        return BaseSolution::create($this->solutionTitle)
            ->setSolutionDescription($this->solutionDescription)
            ->setDocumentationLinks([
                'Tenants' => 'https://tenancyforlaravel.com/docs/v3/tenants',
                'Tenant Identification' => 'https://tenancyforlaravel.com/docs/v3/tenant-identification',
            ]);
    }
}
