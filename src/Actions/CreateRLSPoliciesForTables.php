<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Actions;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

class CreateRLSPoliciesForTables
{
    // protected $signature = 'tenants:create-rls-policies';

    public static function handle()
    {
        $tenantModels = static::getTenantModels();
        $tenantKey = config('tenancy.models.tenant_key_column');

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
                            WHERE ({$tenantKey}::TEXT = (
                                SELECT {$tenantKey}
                                FROM {$parentTable}
                                WHERE id = {$parentKey}
                            ))
                        )
                    )");

                    dump(DB::select("select CURRENT_USER"));

                    DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

                    return Command::SUCCESS;
                } else {
                    $modelName = $model::class;
                    // $this->components->info("Table '$table' is not related to tenant. Make sure $modelName uses the BelongsToPrimaryModel trait.");

                    return Command::FAILURE;
                }
            }

            DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING ({$tenantKey}::TEXT = current_user);");

            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            // $this->components->info("Created RLS policy for table '$table'");
        }

        return Command::SUCCESS;
    }

    public static function getTenantModels(): array
    {
        return array_map(fn (string $modelName) => (new $modelName), config('tenancy.models.rls'));
    }
}
