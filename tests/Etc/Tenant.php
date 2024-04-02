<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests\Etc;

use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Concerns\HasPending;
use Stancl\Tenancy\Database\Models;

/**
 * @method static static create(array $attributes = [])
 */
class Tenant extends Models\Tenant implements TenantWithDatabase
{
    public static array $extraCustomColumns = [];

    use HasDatabase, HasDomains, HasPending;

    public static function getCustomColumns(): array
    {
        return array_merge(parent::getCustomColumns(), static::$extraCustomColumns);
    }
}
