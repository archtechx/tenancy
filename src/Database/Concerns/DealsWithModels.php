<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

trait DealsWithModels
{
    /**
     * If this is not null, use this instead of the default model discovery logic.
     */
    public static Closure|null $modelDiscoveryOverride = null;

    public static array $modelsExcludedFromDiscovery = [];

    /**
     * Discover all models in the directories configured in 'tenancy.rls.model_directories'.
     */
    public static function getModels(): array
    {
        if (static::$modelDiscoveryOverride) {
            return (static::$modelDiscoveryOverride)();
        }

        $modelFiles = Finder::create()->files()->name('*.php')->in(config('tenancy.rls.model_directories'));

        // todo1 Add array property for excluding specific models
        return array_filter(array_map(function (SplFileInfo $file) {
            $fileContents = str($file->getContents());
            $class = $fileContents->after('class ')->before("\n")->explode(' ')->first();

            if ($fileContents->contains('namespace ')) {
                try {
                    return new ($fileContents->after('namespace ')->before(';')->toString() . '\\' . $class);
                } catch (\Throwable $th) {
                    // Skip non-instantiable classes – we only care about models, and those are instantiable
                }
            }

            return null;
        }, iterator_to_array($modelFiles)), fn (object|null $object) => $object instanceof Model && ! in_array($object::class, static::$modelsExcludedFromDiscovery));
    }

    /**
     * Filter all models retrieved by static::getModels() to get only the models that belong to tenants.
     */
    public static function getTenantModels(): array
    {
        return array_filter(static::getModels(), fn (Model $model) => static::modelBelongsToTenant($model) || static::modelBelongsToTenantIndirectly($model));
    }

    public static function modelBelongsToTenant(Model $model): bool
    {
        return in_array(BelongsToTenant::class, class_uses_recursive($model::class));
    }

    public static function modelBelongsToTenantIndirectly(Model $model): bool
    {
        return in_array(BelongsToPrimaryModel::class, class_uses_recursive($model::class));
    }
}
