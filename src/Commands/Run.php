<?php

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Stancl\Tenancy\DatabaseManager;

class Run extends Command
{
    protected $database;

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
    protected $signature = 'tenants:run {command} {--tenants} {args*}';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(DatabaseManager $database)
    {
        parent::__construct();
        $this->database = $database;
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return;
        }

        tenant()->all($this->option('tenants'))->each(function ($tenant) {
            $this->line("Tenant: {$tenant['uuid']} ({$tenant['domain']})");
            tenancy()->init($tenant['domain']);

            // Run command
            // todo
        });

        // todo reconnect to previous tenant or end tenancy if it hadn't been started
    }
}
