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

class CreatePostgresRoleForTenant implements ShouldQueue
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
        $name = $this->tenant->getTenantKey();
        $password = $this->tenant->database()->getPassword() ?? 'password';

        try {
            DB::statement("CREATE ROLE \"$name\" LOGIN PASSWORD '$password';");
        } catch (QueryException $exception) {
            // Skip creating Postgres role if it already exists
        }
    }
}
