<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class DeleteTenantsPostgresUser implements ShouldQueue
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

        // Revoke all permissions of a Postgres user before dropping it
        try {
            DB::statement("DROP OWNED BY \"{$tenantKey}\";");
            DB::statement("DROP USER \"{$tenantKey}\";");
        } catch (QueryException $exception) {
            // Skip dropping permissions if the user doesn't exist
        }
    }
}
