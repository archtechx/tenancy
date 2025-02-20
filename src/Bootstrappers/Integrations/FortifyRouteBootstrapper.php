<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * Allows customizing Fortify action redirects so that they can also redirect
 * to tenant routes instead of just the central routes.
 *
 * This should be used with path/query string identification OR when using Fortify
 * universally, including with domains.
 *
 * When using domain identification, there's no need to pass the tenant parameter,
 * you only want to customize the routes being used, so you can set $passTenantParameter
 * to false.
 */
class FortifyRouteBootstrapper implements TenancyBootstrapper
{
    /**
     * Fortify redirects that should be used in tenant context.
     *
     * Syntax: ['redirect_name' => 'tenant_route_name']
     */
    public static array $fortifyRedirectMap = [];

    /**
     * Should the tenant parameter be passed to fortify routes in the tenant context.
     *
     * This should be enabled with path/query string identification and disabled with domain identification.
     *
     * You may also disable this when using path/query string identification if passing the tenant parameter
     * is handled in another way (TenancyUrlGenerator::$passTenantParameter for both,
     * UrlGeneratorBootstrapper:$addTenantParameterToDefaults for path identification).
     */
    public static bool $passTenantParameter = true;

    /**
     * Tenant route that serves as Fortify's home (e.g. a tenant dashboard route).
     * This route will always receive the tenant parameter.
     */
    public static string|null $fortifyHome = 'tenant.dashboard';

    /**
     * Use default parameter names ('tenant' name and tenant key value) instead of the parameter name
     * and column name configured in the path resolver config.
     *
     * You want to enable this when using query string identification while having customized that config.
     */
    public static bool $defaultParameterNames = false;

    protected array $originalFortifyConfig = [];

    public function __construct(
        protected Repository $config,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalFortifyConfig = $this->config->get('fortify') ?? [];

        $this->useTenantRoutesInFortify($tenant);
    }

    public function revert(): void
    {
        $this->config->set('fortify', $this->originalFortifyConfig);
    }

    protected function useTenantRoutesInFortify(Tenant $tenant): void
    {
        $tenantParameterName = static::$defaultParameterNames ? 'tenant' : PathTenantResolver::tenantParameterName();
        $tenantParameterValue = static::$defaultParameterNames ? $tenant->getTenantKey() : PathTenantResolver::tenantParameterValue($tenant);

        $generateLink = function (string $redirect) use ($tenantParameterValue, $tenantParameterName) {
            return route($redirect, static::$passTenantParameter ? [$tenantParameterName => $tenantParameterValue] : []);
        };

        // Get redirect URLs for the configured redirect routes
        $redirects = array_merge(
            $this->originalFortifyConfig['redirects'] ?? [], // Fortify config redirects
            array_map(fn (string $redirect) => $generateLink($redirect), static::$fortifyRedirectMap), // Mapped redirects
        );

        if (static::$fortifyHome) {
            // Generate the home route URL with the tenant parameter and make it the Fortify home route
            $this->config->set('fortify.home', route(static::$fortifyHome, static::$passTenantParameter ? [$tenantParameterName => $tenantParameterValue] : []));
        }

        $this->config->set('fortify.redirects', $redirects);
    }
}
