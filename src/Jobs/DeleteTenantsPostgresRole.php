<?php

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\SerializesModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Database\QueryException;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class DeleteTenantsPostgresRole implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        protected TenantWithDatabase&Model $tenant,
    ) {
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $tenantKey = $this->tenant->getTenantKey();

        // Revoke all permissions of a role before dropping it
        try {
            DB::statement("DROP OWNED BY \"{$tenantKey}\";");
            DB::statement("DROP ROLE \"{$tenantKey}\";");
        } catch (QueryException $exception) {
            // Skip dropping permissions if the role doesn't exist
        }
    }
}
