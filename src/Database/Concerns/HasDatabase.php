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

        foreach (array_keys($this->getAttributes()) as $key) {
            if (str($key)->startsWith($this->internalPrefix() . 'db_')) {
                if ($key === $this->internalPrefix() . 'db_name') {
                    // Remove DB name because we set that separately
                    continue;
                }

                if ($key === $this->internalPrefix() . 'db_connection') {
                    // Remove DB connection because that's not used for the connection *contents*.
                    // Instead the code uses getInternal('db_connection').
                    continue;
                }

                // We use getAttribute() instead of getting the value directly from the attributes array
                // to support encrypted columns and any other types of casts.
                $databaseConfig[str($key)->after($this->internalPrefix() . 'db_')->toString()] = $this->getAttribute($key);
            }
        }

        return new DatabaseConfig($this, $databaseConfig);
    }
}
