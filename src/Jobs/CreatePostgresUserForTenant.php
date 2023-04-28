<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;

class CreatePostgresUserForTenant implements ShouldQueue
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

        // Create the user only if it doesn't already exist
        // todo1 Create permissions for the user (e.g. permission to create records)
        // todo1 Switch to the Postgres user on TenancyInitialized (purge central DB connection, change credentials in database.connections.pgsql, change database.connections.central to the pgsql connection)
        if (! count(DB::select("SELECT usename FROM pg_user WHERE usename = '$name';")) > 0) {
            DB::statement("CREATE USER \"$name\" LOGIN PASSWORD '$password';");
        }
    }
}
