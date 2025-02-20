<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Bootstrappers;

use Closure;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * While using CLI, automatically alter the root URL used by the URL generator (affects calls like url('/') and route('foo')).
 *
 * Example:
 * Your app's URL (env('APP_URL') / config('app.url') -- the root URL) is http://localhost,
 * you have a tenant with a single subdomain ('acme'),
 * and you want to use that domain as the tenant's 'primary' domain.
 *
 * Using a closure like the one provided in the overrideUrlInTenantContext() method example in TenancyServiceProvider
 * as the $rootUrlOverride property of this class, you can make the URL generator use
 * http://acme.localhost instead of http://localhost as the root URL during the URL generation while in the tenant's context.
 * Meaning, `url('/foo')` (or `URL::to('/foo')`) will return http://acme.localhost/foo.
 */
class RootUrlBootstrapper implements TenancyBootstrapper
{
    /**
     * A closure that accepts the tenant and the original root URL and returns the new root URL.
     * When null, the root URL is not altered in any way.
     *
     * We recommend setting this property in the TenancyServiceProvider's overrideUrlInTenantContext() method.
     */
    public static Closure|null $rootUrlOverride = null;

    protected string|null $originalRootUrl = null;

    /**
     * Overriding the root url may cause issues in *some* tests, so you can disable
     * the behavior by setting this property to false.
     */
    public static bool $rootUrlOverrideInTests = true;

    public function __construct(
        protected Repository $config,
        protected Application $app,
    ) {}

    public function bootstrap(Tenant $tenant): void
    {
        if (static::$rootUrlOverride === null) {
            return;
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        if ($this->app->runningUnitTests() && ! static::$rootUrlOverrideInTests) {
            return;
        }

        $this->originalRootUrl = $this->app['url']->to('/');

        $newRootUrl = (static::$rootUrlOverride)($tenant, $this->originalRootUrl);

        $this->app['url']->forceRootUrl($newRootUrl);
        $this->config->set('app.url', $newRootUrl);
    }

    public function revert(): void
    {
        if ($this->originalRootUrl) {
            $this->app['url']->forceRootUrl($this->originalRootUrl);
            $this->config->set('app.url', $this->originalRootUrl);
        }
    }
}
