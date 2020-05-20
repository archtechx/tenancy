<?php

namespace Stancl\Tenancy\Contracts;

/**
 * @see \Stancl\Tenancy\Database\Models\Tenant
 */
interface Tenant
{
    /** Get the name of the key used for identifying the tenant. */
    public function getTenantKeyName(): string;

    /** Get the value of the key used for identifying the tenant. */
    public function getTenantKey(): ?string;

    /** Get the value of an internal key. */
    public function getInternal(string $key);

    /** Set the value of an internal key. */
    public function setInternal(string $key, $value);

    /** Run a callback in this tenant's environment. */
    public function run(callable $callback);
}
