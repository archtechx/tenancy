<?php

namespace Stancl\Tenancy\Interfaces;

interface TenantModel
{
    /**
     * Get data from the data column.
     *
     * @param string $key
     * @return mixed
     */
    public function getFromData(string $key);

    /**
     * Get data from tenant storage.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key);

    /**
     * Get multiple values from tenant storage.
     *
     * @param array $keys
     * @return array
     */
    public function getMany(array $keys): array;

    /**
     * Put data into tenant storage.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    public function put(string $key, $value);
}
