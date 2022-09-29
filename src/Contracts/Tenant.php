<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Closure;

/**
 * @see \Stancl\Tenancy\Database\Models\Tenant
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
interface Tenant
{
    /** Get the name of the key used for identifying the tenant. */
    public function getTenantKeyName(): string;

    /** Get the value of the key used for identifying the tenant. */
    public function getTenantKey(): int|string;

    /** Get the value of an internal key. */
    public function getInternal(string $key): mixed;

    /** Set the value of an internal key. */
    public function setInternal(string $key, mixed $value): static;

    /** Run a callback in this tenant's environment. */
    public function run(Closure $callback): mixed;
}
