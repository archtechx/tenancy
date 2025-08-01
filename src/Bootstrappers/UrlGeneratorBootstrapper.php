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
     * Should the tenant route parameter get added to TenancyUrlGenerator::defaults().
     *
     * This is recommended when using path identification since defaults() generally has better support in integrations,
     * namely Ziggy, compared to TenancyUrlGenerator::$passTenantParameterToRoutes.
     *
     * With query string identification, this has no effect since URL::defaults() only works for route paramaters.
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
            $tenantParameterName = PathTenantResolver::tenantParameterName();

            $defaultParameters = array_merge($defaultParameters, [
                $tenantParameterName => PathTenantResolver::tenantParameterValue($tenant),
            ]);

            foreach (PathTenantResolver::allowedExtraModelColumns() as $column) {
                $defaultParameters["$tenantParameterName:$column"] = $tenant->getAttribute($column);
            }
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
