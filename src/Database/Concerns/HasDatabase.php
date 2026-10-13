<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\DatabaseConfig;

/**
 * @mixin Model
 */
trait HasDatabase
{
    use HasInternalKeys;

    public function database(): DatabaseConfig
    {
        /** @var TenantWithDatabase&Model $this */
        $databaseConfig = [];

        // Only known keys are read from the tenant's attributes. Passing all tenancy_db_* attributes
        // to the connection config would let anyone who can write tenant attributes (e.g. via mass
        // assignment) set keys like 'url', 'read', 'write', or 'options', and repoint the connection.
        foreach (DatabaseConfig::$tenantConfigKeys as $key) {
            $attribute = $this->internalPrefix() . 'db_' . $key;

            if (array_key_exists($attribute, $this->getAttributes())) {
                // We use getAttribute() instead of getting the value directly from the attributes array
                // to support encrypted columns and any other types of casts.
                $databaseConfig[$key] = $this->getAttribute($attribute);
            }
        }

        return new DatabaseConfig($this, $databaseConfig);
    }
}
