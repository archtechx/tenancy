<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

class CreateRLSPoliciesForTenantTables extends Command
{
    protected $signature = 'tenants:create-rls-policies';

    public function handle(): int
    {
        $tenantModels = $this->getTenantModels();
        $tenantKey = tenancy()->tenantKeyColumn();

        foreach ($tenantModels as $model) {
            $table = $model->getTable();

            DB::statement("DROP POLICY IF EXISTS {$table}_rls_policy ON {$table}");

            if (! Schema::hasColumn($table, $tenantKey)) {
                // Table is not directly related to tenant
                if (in_array(BelongsToPrimaryModel::class, class_uses_recursive($model::class))) {
                    $parentName = $model->getRelationshipToPrimaryModel();
                    $parentKey = $model->$parentName()->getForeignKeyName();
                    $parentModel = $model->$parentName()->make();
                    $parentTable = str($parentModel->getTable())->toString();

                    DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING (
                        {$parentKey} IN (
                            SELECT id
                            FROM {$parentTable}
                            WHERE ({$tenantKey} = (
                                SELECT {$tenantKey}
                                FROM {$parentTable}
                                WHERE id = {$parentKey}
                            ))
                        )
                    )");

                    $this->makeTableUseRls($table);
                } else {
                    $modelName = $model::class;
                    $this->components->info("Table '$table' is not related to tenant. Make sure $modelName uses the BelongsToPrimaryModel trait.");
                }
            } else {
                DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING ({$tenantKey}::TEXT = current_user);");

                $this->makeTableUseRls($table);

                $this->components->info("Created RLS policy for table '$table'");
            }
        }

        return Command::SUCCESS;
    }

    public function getTenantModels(): array
    {
        return array_map(fn (string $modelName) => (new $modelName), config('tenancy.models.rls'));
    }

    protected function makeTableUseRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }
}
