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
use Stancl\Tenancy\Bootstrappers\Integrations\PostgresRLSBootstrapper;
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
        $name = $this->tenant->database()->getUsername() ?? $this->tenant->getTenantKey();
        $password = $this->tenant->database()->getPassword() ?? PostgresRLSBootstrapper::getDefaultPassword();

        // Create the user only if it doesn't already exist
        if (! count(DB::select("SELECT usename FROM pg_user WHERE usename = $1", [$name])) > 0) {
            $formattedStatement = DB::select("SELECT format('CREATE USER %I LOGIN PASSWORD %L', '$name', '$password');")[0]->format;
            DB::statement($formattedStatement);
        }

        $this->grantPermissions((string) $name);
    }

    protected function grantPermissions(string $userName): void
    {
        /** @var \Stancl\Tenancy\Database\Contracts\StatefulTenantDatabaseManager $databaseManager */
        $databaseManager = $this->tenant->database()->manager();

        /** @var Model[] $tenantModels */
        $tenantModels = tenancy()->getTenantModels();

        $databaseManager->database()->transaction(function () use ($userName, $databaseManager, $tenantModels) {
            foreach ($tenantModels as $model) {
                $table = $model->getTable();

                foreach (config('tenancy.rls.user_permissions') as $permission) {
                    $formattedStatement = $databaseManager->database()->select("SELECT format('GRANT %s ON %I TO %I', '$permission', '$table', '$userName')")[0]->format;
                    $databaseManager->database()->statement($formattedStatement);
                }

                $formattedStatement = $databaseManager->database()->select("SELECT format('GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO %I', '$userName')")[0]->format;
                $databaseManager->database()->statement($formattedStatement);
            }
        });
    }
}
