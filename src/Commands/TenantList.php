<?php

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;

class TenantList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenants:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List tenants.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info("Listing all tenants.");
        \tenancy()->all()->each(function ($tenant) {
            $this->line("[Tenant] uuid: {$tenant['uuid']} @ {$tenant['domain']}");
        });
    }
}
