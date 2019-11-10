<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

use Stancl\Tenancy\Tenant;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait TenantAwareCommand
{
    /** @return mixed|void */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $tenants = $this->getTenants();

        if (count($tenants) === 1) {
            return $tenants[0]->run(function () {
                return $this->laravel->call([$this, 'handle']);
            });
        }

        foreach ($tenants as $tenant) {
            $tenant->run(function () {
                $this->laravel->call([$this, 'handle']);
            });
        }
    }

    /**
     * Get an array of tenants for which the command should be executed.
     *
     * @return Tenant[]
     */
    abstract protected function getTenants(): array;
}
