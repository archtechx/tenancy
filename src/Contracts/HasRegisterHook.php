<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

use Illuminate\Contracts\Foundation\Application;

/**
 * Bootstrappers implementing this interface have onRegister() called
 * when TenancyServiceProvider registers them, if they're listed in the
 * tenancy.bootstrappers config.
 *
 * The method only runs once and is unrelated to identification or
 * bootstrapping.
 *
 * Bootstrappers enabled at runtime need onRegister() called manually.
 */
interface HasRegisterHook
{
    public static function onRegister(Application $app): void;
}
