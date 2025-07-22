<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Stancl\Tenancy\Tenancy;

trait InvalidatesResolverCache
{
    public static function bootInvalidatesResolverCache(): void
    {
        static::saved(Tenancy::invalidateResolverCache(...));
        static::deleting(Tenancy::invalidateResolverCache(...));
    }
}
