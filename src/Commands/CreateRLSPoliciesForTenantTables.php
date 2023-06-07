<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates and uses RLS policies for tables related to a tenant directly, or through a parent primary model's table.
 *
 * This command is used with Postgres + single-database tenancy.
 */
class CreateRLSPoliciesForTenantTables extends Command
{
    protected $signature = 'tenants:create-rls-policies';

    public function handle(): int
    {
        DB::transaction(function () {
            tenancy()->getModels()->each(fn (Model $model) => $this->useRlsOnModel($model));
        });

        return Command::SUCCESS;
    }

    /**
     * Make model use RLS if it belongs to a tenant directly, or through a parent primary model.
     */
    protected function useRlsOnModel(Model $model): void
    {
        $table = $model->getTable();
        $tenantKey = tenancy()->tenantKeyColumn();

        DB::statement("DROP POLICY IF EXISTS {$table}_rls_policy ON {$table}");

        if (tenancy()->modelBelongsToTenant($model)) {
            DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING ({$tenantKey}::TEXT = current_user);");

            $this->enableRls($table);

            $this->components->info("Created RLS policy for table '$table'");
        }

        if (tenancy()->modelBelongsToTenantIndirectly($model)) {
            /** @phpstan-ignore-next-line */
            $parentName = $model->getRelationshipToPrimaryModel();
            $parentKey = $model->$parentName()->getForeignKeyName();
            $parentTable = $model->$parentName()->make()->getTable();

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

            $this->enableRls($table);
        }
    }

    protected function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }
}
