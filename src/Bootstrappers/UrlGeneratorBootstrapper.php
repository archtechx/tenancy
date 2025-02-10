<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Makes the app use TenancyUrlGenerator (instead of Illuminate\Routing\UrlGenerator) which:
 * - prefixes route names with the tenant route name prefix (PathTenantResolver::tenantRouteNamePrefix() by default)
 * - passes the tenant parameter to the link generated by route() and temporarySignedRoute() (PathTenantResolver::tenantParameterName() by default).
 *
 * Used with path and query string identification.
 *
 * @see TenancyUrlGenerator
 * @see PathTenantResolver
 */
class UrlGeneratorBootstrapper implements TenancyBootstrapper
{
    /**
     * Determine if the tenant route parameter should get added to the defaults of the TenancyUrlGenerator.
     *
     * This is preferrable with path identification since the tenant parameter is passed to the tenant routes automatically,
     * even with integrations like the Ziggy route() helper.
     *
     * With query strig identification, this essentialy has no effect because URL::defaults() works only for route paramaters,
     * not for query strings.
     */
    public static bool $addTenantParameterToDefaults = true;

    public function __construct(
        protected Application $app,
        protected UrlGenerator $originalUrlGenerator,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        URL::clearResolvedInstances();

        $this->useTenancyUrlGenerator($tenant);
    }

    public function revert(): void
    {
        $this->app->extend('url', fn () => $this->originalUrlGenerator);
    }

    /**
     * Make 'url' resolve to an instance of TenancyUrlGenerator.
     *
     * @see \Illuminate\Routing\RoutingServiceProvider registerUrlGenerator()
     */
    protected function useTenancyUrlGenerator(Tenant $tenant): void
    {
        $newGenerator = new TenancyUrlGenerator(
            $this->app['router']->getRoutes(),
            $this->originalUrlGenerator->getRequest(),
            $this->app['config']->get('app.asset_url'),
        );

        $defaultParameters = $this->originalUrlGenerator->getDefaultParameters();

        if (static::$addTenantParameterToDefaults) {
            $defaultParameters = array_merge(
                $defaultParameters,
                [PathTenantResolver::tenantParameterName() => $tenant->getTenantKey()]
            );
        }

        $newGenerator->defaults($defaultParameters);

        $newGenerator->setSessionResolver(function () {
            return $this->app['session'] ?? null;
        });

        $newGenerator->setKeyResolver(function () {
            return $this->app->make('config')->get('app.key');
        });

        $this->app->extend('url', fn () => $newGenerator);
    }
}
