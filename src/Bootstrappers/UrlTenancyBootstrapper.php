<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Routing\UrlGenerator;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

class UrlTenancyBootstrapper implements TenancyBootstrapper
{
    public static Closure|null $rootUrlOverride = null;
    protected string|null $originalRootUrl = null;

    public function __construct(
        protected UrlGenerator $urlGenerator,
        protected Repository $config,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalRootUrl = $this->urlGenerator->to('/');

        if (static::$rootUrlOverride) {
            $newRootUrl = (static::$rootUrlOverride)($tenant);

            $this->urlGenerator->forceRootUrl($newRootUrl);
            $this->config->set('app.url', $newRootUrl);
        }
    }

    public function revert(): void
    {
        $this->urlGenerator->forceRootUrl($this->originalRootUrl);
        $this->config->set('app.url', $this->originalRootUrl);
    }
}
