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

        foreach ($this->getAttributes() as $key => $value) {
            if (str($key)->startsWith($this->internalPrefix() . 'db_')) {
                if ($key === $this->internalPrefix() . 'db_name') {
                    // Remove DB name because we set that separately
                    continue;
                }

                if ($key === $this->internalPrefix() . 'db_connection') {
                    // Remove DB connection because that's not used here
                    continue;
                }

                $databaseConfig[str($key)->after($this->internalPrefix() . 'db_')->toString()] = $value;
            }
        }

        return new DatabaseConfig($this, $databaseConfig);
    }
}
