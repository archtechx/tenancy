<?php

declare(strict_types=1);

use Stancl\Tenancy\Tenant;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait TenantAwareCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        array_map(function (Tenant $tenant) {
            $tenant->run(function () {
                $this->laravel->call([$this, 'handle']);
            });
        }, $this->getTenants());
    }

    /**
     * Get an array of tenants for which the command should be executed.
     *
     * @return Tenant[]
     */
    abstract protected function getTenants(): array;
}
