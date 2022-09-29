<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Contracts;

use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\DatabaseConfig;

interface TenantWithDatabase extends Tenant
{
    /** Get the tenant's database config. */
    public function database(): DatabaseConfig;

    /** Get the internal prefix. */
    public static function internalPrefix(): string;

    /** Get an internal key. */
    public function getInternal(string $key): mixed;

    /** Set internal key. */
    public function setInternal(string $key, mixed $value): static;
}
