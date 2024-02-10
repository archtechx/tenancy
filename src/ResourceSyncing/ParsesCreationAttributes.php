<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

trait ParsesCreationAttributes
{
    protected function parseCreationAttributes(Syncable&Model $resource): array
    {
        $creationAttributes = $resource->getCreationAttributes();

        // Merge the provided attribute names (['attribute']) with the provided defaults (['attribute2' => 'default_value'])
        // This allows mixing the two formats of providing the creation attributes (['attribute', 'attribute2' => 'default_value'])
        [$creationAttributeNames, $defaults] = [
            Arr::where($creationAttributes, fn ($value, $key) => is_numeric($key)),
            Arr::where($creationAttributes, fn ($value, $key) => is_string($key)),
        ];

        $attributeNames = array_merge($resource->getSyncedAttributeNames(), $creationAttributeNames);

        return array_merge($resource->only($attributeNames), $defaults);
    }
}
