<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Traits;

use Stancl\Tenancy\Tenant;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait TenantAwareCommand
{
    /** @return int */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $callback = $this->runBeforeRunningTenantsHook();

        if ($callback !== 0) {
            return $callback;
        }

        $tenants = $this->getTenants();
        $exitCode = 0;

        foreach ($tenants as $tenant) {
            $result = (int) $tenant->run(function () {
                return $this->laravel->call([$this, 'handle']);
            });

            if ($result !== 0) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    /**
     * Get an array of tenants for which the command should be executed.
     *
     * @return Tenant[]
     */
    abstract protected function getTenants(): array;

    private function runBeforeRunningTenantsHook()
    {
        if (! method_exists($this, 'beforeRunningTenants')) {
            return;
        }

        return (int) $this->laravel->call([$this, 'beforeRunningTenants']);
    }
}
