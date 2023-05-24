<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

class CreateRLSPoliciesForTenantTables extends Command
{
    protected $signature = 'tenants:create-rls-policies';

    public function handle(): int
    {
        foreach (config('tenancy.models.rls') as $modelClass) {
            $this->makeModelUseRls((new $modelClass));
        }

        return Command::SUCCESS;
    }

    protected function makeModelUseRls(Model $model): void
    {
        $table = $model->getTable();
        $tenantKey = tenancy()->tenantKeyColumn();

        DB::transaction(fn () => DB::statement("DROP POLICY IF EXISTS {$table}_rls_policy ON {$table}"));

        if (! Schema::hasColumn($table, $tenantKey)) {
            // Table is not directly related to tenant
            if (in_array(BelongsToPrimaryModel::class, class_uses_recursive($model::class))) {
                $this->makeSecondaryModelUseRls($model);
            } else {
                $modelName = $model::class;

                $this->components->info("Table '$table' is not related to tenant. Make sure $modelName uses the BelongsToPrimaryModel trait.");
            }
        } else {
            DB::transaction(fn () => DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING ({$tenantKey}::TEXT = current_user);"));

            $this->enableRls($table);

            $this->components->info("Created RLS policy for table '$table'");
        }
    }

    protected function makeSecondaryModelUseRls(Model $model): void
    {
        $table = $model->getTable();
        $tenantKey = tenancy()->tenantKeyColumn();

        $parentName = $model->getRelationshipToPrimaryModel();
        $parentKey = $model->$parentName()->getForeignKeyName();
        $parentModel = $model->$parentName()->make();
        $parentTable = str($parentModel->getTable())->toString();

        DB::transaction(fn () => DB::statement("CREATE POLICY {$table}_rls_policy ON {$table} USING (
            {$parentKey} IN (
                SELECT id
                FROM {$parentTable}
                WHERE ({$tenantKey} = (
                    SELECT {$tenantKey}
                    FROM {$parentTable}
                    WHERE id = {$parentKey}
                ))
            )
        )"));

        $this->enableRls($table);
    }

    protected function enableRls(string $table): void
    {
        DB::transaction(function () use ($table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        });
    }
}
