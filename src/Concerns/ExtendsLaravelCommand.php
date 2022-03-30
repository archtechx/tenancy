<?php

namespace Stancl\Tenancy\Concerns;

trait ExtendsLaravelCommand
{
    public function getName(): ?string
    {
        return static::getDefaultName();
    }

    public static function getDefaultName(): ?string
    {
        if (method_exists(static::class, 'getTenantCommandName')) {
            return static::getTenantCommandName();
        }

        return 'tenants:' . parent::getDefaultName();
    }
}
