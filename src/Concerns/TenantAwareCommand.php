<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Concerns;

use Closure;
use Stancl\Tenancy\Contracts\Tenant;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

trait TenantAwareCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Laravel's execute() calls handle() (or __invoke()) through the container,
        // so binding the method lets us run it for each tenant while keeping the rest
        // of execute() (isolation, fail(), etc.) intact
        $method = method_exists($this, 'handle') ? 'handle' : '__invoke';

        $this->laravel->bindMethod([static::class, $method], function (self $command) use ($method) {
            return $command->executeForTenants($command->$method(...));
        });

        return parent::execute($input, $output);
    }

    protected function executeForTenants(Closure $handler): int
    {
        $exitCode = static::SUCCESS;

        foreach ($this->getTenants() as $tenant) {
            $result = (int) $tenant->run(fn () => $this->laravel->call($handler));

            if ($result !== static::SUCCESS) {
                $exitCode = $result;
            }
        }

        return $exitCode;
    }

    /**
     * Get an array of tenants for which the command should be executed.
     *
     * @return Tenant[]|mixed
     */
    abstract protected function getTenants();
}
