<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        $tables = array_map(fn ($table) => $table->tablename, Schema::getAllTables());

        return array_filter($tables, fn ($table) => Schema::hasColumn($table, config('tenancy.models.tenant_key_column')));
    }
}
