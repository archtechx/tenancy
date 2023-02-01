<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class UrlTenancyBootstrapper implements TenancyBootstrapper
{
    public static Closure|null $rootUrlOverride = null;
    protected string|null $originalRootUrl = null;

    public function __construct(
        protected UrlGenerator $urlGenerator,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalRootUrl = $this->urlGenerator->to('/');

        if (static::$rootUrlOverride) {
            $this->urlGenerator->forceRootUrl((static::$rootUrlOverride)($tenant));
        }
    }

    public function revert(): void
    {
        $this->urlGenerator->forceRootUrl($this->originalRootUrl);
    }
}
