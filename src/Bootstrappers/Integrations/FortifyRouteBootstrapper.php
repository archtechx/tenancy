<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Enums\Context;
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
     * Make Fortify actions redirect to custom routes.
     *
     * For each route redirect, specify the intended route context (central or tenant).
     * Based on the provided context, we pass the tenant parameter to the route (or not).
     * The tenant parameter is only passed to the route when you specify its context as tenant.
     *
     * The route redirects should be in the following format:
     *
     * 'fortify_action' => [
     *     'route_name' => 'tenant.route',
     *     'context' => Context::TENANT,
     * ]
     *
     * For example:
     *
     * FortifyRouteBootstrapper::$fortifyRedirectMap = [
     *     // On logout, redirect the user to the "bye" route in the central app
     *     'logout' => [
     *         'route_name' => 'bye',
     *         'context' => Context::CENTRAL,
     *     ],
     *
     *     // On login, redirect the user to the "welcome" route in the tenant app
     *     'login' => [
     *        'route_name' => 'welcome',
     *        'context' => Context::TENANT,
     *     ],
     * ];
     */
    public static array $fortifyRedirectMap = [];

    /**
     * Should the tenant parameter be passed to fortify routes in the tenant context.
     *
     * This should be enabled with path/query string identification and disabled with domain identification
     */
    public static bool $passTenantParameter = true;

    /**
     * Tenant route that serves as Fortify's home (e.g. a tenant dashboard route).
     * This route will always receive the tenant parameter.
     */
    public static string $fortifyHome = 'tenant.dashboard';

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
        // todo0 this should be just using 'tenant' and the tenant key with query identification - if we can't detect that easily, just add a static property for query id (default false)
        $tenantParameterName = PathTenantResolver::tenantParameterName();
        $tenantParameterValue = PathTenantResolver::tenantParameterValue($tenant);

        $generateLink = function (array $redirect) use ($tenantParameterValue, $tenantParameterName) {
            // Specifying the context is only required with query string identification
            // because with path identification, the tenant parameter should always present
            $passTenantParameter = static::$passTenantParameter && $redirect['context'] === Context::TENANT;

            // Only pass the tenant parameter when the user should be redirected to a tenant route
            return route($redirect['route_name'], $passTenantParameter ? [$tenantParameterName => $tenantParameterValue] : []);
        };

        // Get redirect URLs for the configured redirect routes
        $redirects = array_merge(
            $this->originalFortifyConfig['redirects'] ?? [], // Fortify config redirects
            array_map(fn (array $redirect) => $generateLink($redirect), static::$fortifyRedirectMap), // Mapped redirects
        );

        if (static::$fortifyHome) {
            // Generate the home route URL with the tenant parameter and make it the Fortify home route
            // todo0 this should ALSO be only when static::$passTenantParameter, otherwise [], but shouldn't we also check the context here?
            $this->config->set('fortify.home', route(static::$fortifyHome, [$tenantParameterName => $tenantParameterValue]));
        }

        $this->config->set('fortify.redirects', $redirects);
    }
}
