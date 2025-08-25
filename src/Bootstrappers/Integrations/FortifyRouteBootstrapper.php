<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;

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
    public static bool $passTenantParameter = false;

    /**
     * Tenant route that serves as Fortify's home (e.g. a tenant dashboard route).
     * This route will always receive the tenant parameter.
     */
    public static string|null $fortifyHome = 'tenant.dashboard';

    /**
     * Follow the query_parameter config instead of the tenant_parameter_name (path identification) config.
     *
     * This only has an effect when:
     *   - $passTenantParameter is enabled, and
     *   - the tenant_parameter_name config for the path resolver differs from the query_parameter config for the request data resolver.
     *
     * In such a case, instead of adding ['tenant' => '...'] to the route parameters (or whatever your tenant_parameter_name is if not 'tenant'),
     * the query_parameter will be passed instead, e.g. ['team' => '...'] if your query_parameter config is 'team'.
     *
     * This is enabled by default because typically you will not need $passTenantParameter with path identification.
     * UrlGeneratorBootstrapper::$addTenantParameterToDefaults is recommended instead when using path identification.
     *
     * On the other hand, when using request data identification (specifically query string) you WILL need to
     * pass the parameter therefore you would use $passTenantParameter.
     */
    public static bool $passQueryParameter = true;

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
        if (static::$passQueryParameter) {
            $tenantParameterName = RequestDataTenantResolver::queryParameterName();
            $tenantParameterValue = RequestDataTenantResolver::payloadValue($tenant);
        } else {
            $tenantParameterName = PathTenantResolver::tenantParameterName();
            $tenantParameterValue = PathTenantResolver::tenantParameterValue($tenant);
        }

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
            $this->config->set('fortify.home', $generateLink(static::$fortifyHome));
        }

        $this->config->set('fortify.redirects', $redirects);
    }
}
