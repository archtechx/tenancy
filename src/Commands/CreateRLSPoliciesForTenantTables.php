<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

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
        foreach ($this->getModels() as $model) {
            DB::transaction(fn () => $this->useRlsOnModel($model));
        }

        return Command::SUCCESS;
    }

    protected function getModels(): array
    {
        $tables = array_map(fn ($table) => $table->tablename, Schema::getAllTables());
        $models = array_map(fn (string $table) => $this->getModelFromTable($table), $tables);

        return array_filter($models);
    }

    /**
     * Make model use RLS if it belongs to a tenant directly, or through a parent primary model.
     */
    protected function useRlsOnModel(Model $model): void
    {
        $table = $model->getTable();
        $tenantKey = tenancy()->tenantKeyColumn();

        DB::statement("DROP POLICY IF EXISTS {$table}_rls_policy ON {$table}");

        if (! Schema::hasColumn($table, $tenantKey)) {
            // Table is not directly related to a tenant
            if (in_array(BelongsToPrimaryModel::class, class_uses_recursive($model::class))) {
                $this->makeSecondaryModelUseRls($model);
            } else {
                $this->components->info("Skipping RLS policy creation – table '$table' is not related to a tenant.");
            }
        } else {
            DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING ({$tenantKey}::TEXT = current_user);");

            $this->enableRls($table);

            $this->components->info("Created RLS policy for table '$table'");
        }
    }

    protected function makeSecondaryModelUseRls(Model $model): void
    {
        $table = $model->getTable();
        $tenantKey = tenancy()->tenantKeyColumn();

        /** @phpstan-ignore-next-line */
        $parentName = $model->getRelationshipToPrimaryModel();
        $parentKey = $model->$parentName()->getForeignKeyName();
        $parentModel = $model->$parentName()->make();
        $parentTable = $parentModel->getTable();

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

    protected function enableRls(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
    }

    protected function getModelFromTable(string $table): Model|null
    {
        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, Model::class)) {
                $model = new $class;

                if ($model->getTable() === $table) {
                    return $model;
                }
            }
        }

        return null;
    }
}
