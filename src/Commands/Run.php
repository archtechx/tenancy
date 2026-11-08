<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Stancl\Tenancy\Concerns\HasTenantOptions;
use Symfony\Component\Console\Input\StringInput;

class Run extends Command
{
    use HasTenantOptions;

    protected $description = 'Run a command for tenant(s)';

    protected $signature = 'tenants:run {commandname : The artisan command.}';

    public function handle(): int
    {
        /** @var string $commandName */
        $commandName = $this->argument('commandname');

        $stringInput = new StringInput($commandName);
        $exitCode = 0;

        tenancy()->runForMultiple($this->getTenants(), function ($tenant) use ($stringInput, &$exitCode) {
            $this->components->info("Tenant: {$tenant->getTenantKey()}");

            $verbosity = $this->output->getVerbosity();
            $decorated = $this->output->isDecorated();

            $tenantExitCode = $this->getLaravel()
                ->make(Kernel::class)
                ->handle($stringInput, $this->output);

            // Output options passed to the command (e.g. -q or --no-ansi) shouldn't persist beyond its run
            $this->output->setVerbosity($verbosity);
            $this->output->setDecorated($decorated);

            $exitCode = $exitCode ?: $tenantExitCode;
        });

        return $exitCode;
    }
}
