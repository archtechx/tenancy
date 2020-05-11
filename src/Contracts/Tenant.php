<?php

namespace Stancl\Tenancy\Contracts;

/**
 * @see \Stancl\Tenancy\Database\Models\Tenant
 */
interface Tenant
{
    public function getTenantKeyName(): string;
    public function getTenantKey(): string;
    public function run(callable $callback);
}