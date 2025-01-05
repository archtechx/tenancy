<?php

declare(strict_types=1);

namespace Stancl\Tenancy\RLS\PolicyManagers;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class TraitRLSManager implements RLSPolicyManager
{
    /** @var Closure(): array<Model> */
    public static Closure|null $modelDiscoveryOverride = null;

    /**
     * Directories in which the manager will discover your models.
     * Subdirectories of the specified directories are also scanned.
     *
     * For example, specifying 'app/Models' will discover all models in the 'app/Models' directory and all of its subdirectories.
     * Specifying 'app/Models/*' will discover all models in the subdirectories of 'app/Models' (+ their subdirectories),
     * but not the models present directly in the 'app/Models' directory.
     */
    public static array $modelDirectories = ['app/Models'];

    /**
     * Scope queries of all tenant models using RLS by default.
     *
     * To use RLS scoping only for some models, you can keep this disabled and
     * make the models of your choice implement the RLSModel interface.
     */
    public static bool $implicitRLS = false;

    /** @var array<class-string<Model>> */
    public static array $excludedModels = [];

    public function generateQueries(): array
    {
        $queries = [];

        foreach ($this->getModels() as $model) {
            $table = $model->getTable();

            if ($this->modelBelongsToTenant($model)) {
                $queries[$table] = $this->generateDirectRLSPolicyQuery($model);
            }

            if ($this->modelBelongsToTenantIndirectly($model)) {
                $parentRelationship = $model->{$model->getRelationshipToPrimaryModel()}();

                $queries[$table] = $this->generateIndirectRLSPolicyQuery($model, $parentRelationship);
            }
        }

        return $queries;
    }

    protected function generateDirectRLSPolicyQuery(Model $model): string
    {
        $table = $model->getTable();
        $tenantKeyColumn = tenancy()->tenantKeyColumn();
        $sessionTenantKey = config('tenancy.rls.session_variable_name');

        return <<<SQL
        CREATE POLICY {$table}_rls_policy ON {$table} USING (
            {$tenantKeyColumn}::text = current_setting('{$sessionTenantKey}')
        );
        SQL;
    }

    /**
     * @param BelongsTo<Model, Model> $parentRelationship
     */
    protected function generateIndirectRLSPolicyQuery(Model $model, BelongsTo $parentRelationship): string
    {
        $table = $model->getTable();
        $parent = $parentRelationship->getModel();
        $tenantKeyColumn = $parent->tenant()->getForeignKeyName();
        $sessionTenantKey = config('tenancy.rls.session_variable_name');

        return <<<SQL
        CREATE POLICY {$table}_rls_policy ON {$table} USING (
            {$parentRelationship->getForeignKeyName()} IN (
                SELECT {$parent->getKeyName()}
                FROM {$parent->getTable()}
                WHERE {$tenantKeyColumn}::text = current_setting('{$sessionTenantKey}')
            )
        );
        SQL;
    }

    /**
     * Discover and retrieve all models.
     *
     * Models are either discovered in the directories specified in static::$modelDirectories (by default),
     * or by a custom closure specified in static::$modelDiscoveryOverride.
     *
     * @return array<Model>
     */
    public function getModels(): array
    {
        if (static::$modelDiscoveryOverride) {
            return (static::$modelDiscoveryOverride)();
        }

        $modelFiles = Finder::create()->files()->name('*.php')->in(static::$modelDirectories);

        return array_filter(array_map(function (SplFileInfo $file) {
            $fileContents = str($file->getContents());
            $class = $fileContents->after("\nclass ")->before("\n")->explode(' ')->first();

            if ($fileContents->contains('namespace ')) {
                try {
                    return ($fileContents->after('namespace ')->before(';')->toString() . '\\' . $class)::make();
                } catch (\Throwable $th) {
                    // Skip non-instantiable classes – we only care about models, and those are instantiable
                }
            }

            return null;
        }, iterator_to_array($modelFiles)), fn (object|null $object) => $object instanceof Model && ! in_array($object::class, static::$excludedModels));
    }

    public function modelBelongsToTenant(Model $model): bool
    {
        return in_array(BelongsToTenant::class, class_uses_recursive($model::class));
    }

    public function modelBelongsToTenantIndirectly(Model $model): bool
    {
        return in_array(BelongsToPrimaryModel::class, class_uses_recursive($model::class));
    }
}
