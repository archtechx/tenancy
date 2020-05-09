<?php

// todo move namespace one dir above
namespace Stancl\Tenancy\Database\Models\Concerns;

// todo rename
trait HasADataColumn
{
    public static function bootHasADataColumn()
    {
        $encode = function (self $model) {
            foreach ($model->getAttributes() as $key => $value) {
                if (! in_array($key, static::getCustomColums())) {
                    $current = $model->getAttribute(static::getDataColumn()) ?? [];

                    $model->setAttribute(static::getDataColumn(), array_merge($current, [
                        $key => $value,
                    ]));

                    unset($model->attributes[$key]);
                }
            }
        };

        $decode = function (self $model) {
            foreach ($model->getAttribute(static::getDataColumn()) ?? [] as $key => $value) {
                $model->setAttribute($key, $value);
            }

            $model->setAttribute(static::getDataColumn(), null);
        };

        static::saving($encode);
        static::saved($decode);
        static::retrieved($decode);
    }

    public function getCasts()
    {
        return array_merge(parent::getCasts(), [
            static::getDataColumn() => 'array',
        ]);
    }

    /**
     * Get the name of the column that stores additional data.
     */
    public static function getDataColumn(): string
    {
        return 'data';
    }

    public static function getCustomColums(): array
    {
        return array_merge(['id'], config('tenancy.custom_columns'));
    }
}