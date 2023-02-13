<?php

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CreateRLSPoliciesForTenantTables extends Command
{
    protected $signature = 'tenants:create-rls-policies';

    public function handle(): int
    {
        foreach ($this->getTenantTables() as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_rls_policy ON {$table};");
            DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING (tenant_id::TEXT = current_user);");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            $this->components->info("Created RLS policy for table '$table'");
        }


        return Command::SUCCESS;
    }

    protected function getTenantTables(): array
    {
        return array_map(function (string $migration) {
            return str($migration)->after('create_')->before('_table')->toString();
        }, File::files('./database/migrations/tenant'));
    }
}
