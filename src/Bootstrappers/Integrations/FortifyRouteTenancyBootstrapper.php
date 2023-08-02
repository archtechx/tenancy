<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers\Integrations;

use Illuminate\Config\Repository;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Allows customizing Fortify redirect URLs.
 * Intended to be used with UrlBindingBootstrapper.
 *
 * @see \Stancl\Tenancy\Bootstrappers\UrlBindingBootstrapper
 */
class FortifyRouteTenancyBootstrapper implements TenancyBootstrapper
{
    // 'fortify_action' => 'tenant_route_name'
    public static array $fortifyRedirectTenantMap = [
        // 'logout' => 'welcome',
    ];

    // Fortify home route name
    public static string|null $fortifyHome = 'dashboard';
    protected array|null $originalFortifyConfig = null;

    public function __construct(
        protected Repository $config,
    ) {
    }

    public function bootstrap(Tenant $tenant): void
    {
        $this->originalFortifyConfig = $this->config->get('fortify');

        $this->useTenantRoutesInFortify();
    }

    public function revert(): void
    {
        $this->config->set('fortify', $this->originalFortifyConfig);
    }

    protected function useTenantRoutesInFortify(): void
    {
        // Regenerate the URLs after the behavior of the route() helper has been modified
        // in UrlBindingBootstrapper to generate URLs specific to the current tenant
        $tenantRoutes = array_map(fn (string $routeName) => route($routeName), static::$fortifyRedirectTenantMap);

        if (static::$fortifyHome) {
            $this->config->set('fortify.home', route(static::$fortifyHome));
        }

        $this->config->set('fortify.redirects', array_merge($this->config->get('fortify.redirects') ?? [], $tenantRoutes));
    }
}
