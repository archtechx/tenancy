<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

trait DealsWithModels
{
    public static Closure|null $modelDiscoveryOverride = null;

    /**
     * Discover all models in the directories configured in 'tenancy.rls.model_directories'.
     */
    public static function getModels(): array
    {
        if (static::$modelDiscoveryOverride) {
            return (static::$modelDiscoveryOverride)();
        }

        $modelFiles = Finder::create()->files()->name('*.php')->in(config('tenancy.rls.model_directories'));

        return array_filter(array_map(function (SplFileInfo $file) {
            $fileContents = str($file->getContents());
            $class = $fileContents->after('class ')->before("\n")->explode(' ')->first();

            if ($fileContents->contains('namespace ')) {
                $class = $fileContents->after('namespace ')->before(';')->toString() . '\\' . $class;
                $reflection = new ReflectionClass($class);

                // Skip non-instantiable classes – we only care about models, and those are instantiable
                if ($reflection->getConstructor()?->getNumberOfRequiredParameters() === 0) {
                    $object = new $class;

                    if ($object instanceof Model) {
                        return $object;
                    }
                }
            }

            return null;
        }, iterator_to_array($modelFiles)));
    }

    /**
     * Filter all models retrieved by static::getModels() to get only the models that belong to tenants.
     */
    public static function getTenantModels(): array
    {
        return array_filter(static::getModels(), fn (Model $model) => tenancy()->modelBelongsToTenant($model) || tenancy()->modelBelongsToTenantIndirectly($model));
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
