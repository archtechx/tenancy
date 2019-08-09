<?php

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class Run extends Command
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run a command for tenant(s)';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:run {commandname} {--tenants=} {args*}';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if ($tenancy_was_initialized = tenancy()->initialized) {
            $previous_tenants_domain = tenant('domain');
        }

        tenant()->all($this->option('tenants'))->each(function ($tenant) {
            $this->line("Tenant: {$tenant['uuid']} ({$tenant['domain']})");
            tenancy()->init($tenant['domain']);

            // Run command
            Artisan::call($this->argument('commandname'), [
                'args' => $this->argument('args'), // todo find a better way to pass args
            ]);
            tenancy()->end();
        });

        if ($tenancy_was_initialized) {
            tenancy()->init($previous_tenants_domain);
        }
    }
}
