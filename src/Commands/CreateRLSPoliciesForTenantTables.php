<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates and uses RLS policies for tables of models related to a tenant directly (using BelongsToTenant),
 * or through a parent primary model (using BelongsToPrimaryModel).
 *
 * The models are discovered in the directories configured in the Tenancy config ('tenancy.rls.model_directories').
 *
 * This command is used with Postgres + single-database tenancy.
 */
class CreateRLSPoliciesForTenantTables extends Command
{
    protected $signature = 'tenants:create-rls-policies';

    public function handle(): int
    {
        DB::transaction(function () {
            foreach (tenancy()->getTenantModels() as $model) {
                $this->applyRlsOnModel($model);
            }
        });

        return Command::SUCCESS;
    }

    /**
     * Make model use RLS if it belongs to a tenant directly, or through a parent primary model.
     */
    protected function applyRlsOnModel(Model $model): void
    {
        $table = $model->getTable();
        $tenantKeyName = tenancy()->tenantKeyColumn();

        DB::statement("DROP POLICY IF EXISTS {$table}_rls_policy ON {$table}");

        if (tenancy()->modelBelongsToTenant($model)) {
            DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING ({$tenantKeyName}::TEXT = current_user);");

            $this->enableRls($table);

            $this->components->info("Created RLS policy for table '$table'");
        }

        if (tenancy()->modelBelongsToTenantIndirectly($model)) {
            /** @phpstan-ignore-next-line */
            $parentName = $model->getRelationshipToPrimaryModel();
            $parentKeyName = $model->$parentName()->getForeignKeyName();
            $parentTable = $model->$parentName()->make()->getTable();

            DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING (
                {$parentKeyName} IN (
                    SELECT id
                    FROM {$parentTable}
                    WHERE ({$tenantKeyName} = (
                        SELECT {$tenantKeyName}
                        FROM {$parentTable}
                        WHERE id = {$parentKeyName}
                    ))
                )
            )");

            $this->enableRls($table);

            $this->components->info("Created RLS policy for table '$table'");
        }
    }

    protected function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }
}
