<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Overrides;

use BackedEnum;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Stancl\Tenancy\Resolvers\PathTenantResolver;

/**
 * This class is used in place of the default UrlGenerator when UrlGeneratorBootstrapper is enabled.
 *
 * TenancyUrlGenerator does a few extra things:
 *   - Autofills the tenant parameter in the tenant context with the current tenant.
 *     This is done either by:
 *       - URL::defaults() -- if UrlGeneratorBootstrapper::$addTenantParameterToDefaults is enabled.
 *         This generally has the best support since tools like e.g. Ziggy read defaults().
 *       - Automatically passing ['tenant' => ...] to each route() call -- if TenancyUrlGenerator::$passTenantParameterToRoutes is enabled
 *         This is a more universal solution since it supports both path identification and query parameter identification.
 *
 *   - Prepends route names passed to route() and URL::temporarySignedRoute()
 *     with `tenant.` (or the configured prefix) if $prefixRouteNames is enabled.
 *     This is primarily useful when using route cloning with path identification.
 *
 * To bypass this behavior on any single route() call, pass the $bypassParameter as true (['central' => true] by default).
 */
class TenancyUrlGenerator extends UrlGenerator
{
    /**
     * Parameter which works as a flag for bypassing the behavior modification of route() and temporarySignedRoute().
     *
     * For example, in tenant context:
     * Route::get('/', ...)->name('home');
     * // query string identification
     * Route::get('/tenant', ...)->middleware(InitializeTenancyByRequestData::class)->name('tenant.home');
     * - route('home') => app.test/tenant?tenant=tenantKey
     * - route('home', [$bypassParameter => true]) => app.test/
     * - route('tenant.home', [$bypassParameter => true]) => app.test/tenant -- no query string added
     *
     * Note: UrlGeneratorBootstrapper::$addTenantParameterToDefaults is not affected by this, though
     * it doesn't matter since it doesn't pass any extra parameters when not needed.
     *
     * @see UrlGeneratorBootstrapper
     */
    public static string $bypassParameter = 'central';

    /**
     * Should route names passed to route() or temporarySignedRoute()
     * get prefixed with the tenant route name prefix.
     *
     * This is useful when using e.g. path identification with third-party packages
     * where you don't have control over all route() calls or don't want to change
     * too many files. Often this will be when using route cloning.
     */
    public static bool $prefixRouteNames = false;

    /**
     * Should the tenant parameter be passed to route() or temporarySignedRoute() calls.
     *
     * This is useful with path or query parameter identification. The former can be handled
     * more elegantly using UrlGeneratorBootstrapper::$addTenantParameterToDefaults.
     *
     * @see UrlGeneratorBootstrapper
     */
    public static bool $passTenantParameterToRoutes = false;

    /**
     * Route name overrides.
     *
     * Note: This behavior can be bypassed using $bypassParameter just like
     * $prefixRouteNames and $passTenantParameterToRoutes.
     *
     * Example from a Jetstream integration:
     * [
     *     'profile.show' => 'tenant.profile.show',
     *     'two-factor.login' => 'tenant.two-factor.login',
     * ]
     *
     * In the tenant context:
     * - `route('profile.show')` will return a URL as if you called `route('tenant.profile.show')`.
     * - `route('profile.show', ['central' => true])` will return a URL as if you called `route('profile.show')`.
     */
    public static array $overrides = [];

    /**
     * Use default parameter names ('tenant' name and tenant key value) instead of the parameter name
     * and column name configured in the path resolver config.
     *
     * You want to enable this when using query string identification while having customized that config.
     */
    public static bool $defaultParameterNames = false;

    /**
     * Override the route() method so that the route name gets prefixed
     * and the tenant parameter gets added when in tenant context.
     */
    public function route($name, $parameters = [], $absolute = true)
    {
        if ($name instanceof BackedEnum && ! is_string($name = $name->value)) { // @phpstan-ignore function.impossibleType
            throw new InvalidArgumentException('Attribute [name] expects a string backed enum.');
        }

        [$name, $parameters] = $this->prepareRouteInputs($name, Arr::wrap($parameters)); // @phpstan-ignore argument.type

        return parent::route($name, $parameters, $absolute);
    }

    /**
     * Override the temporarySignedRoute() method so that the route name gets prefixed
     * and the tenant parameter gets added when in tenant context.
     */
    public function temporarySignedRoute($name, $expiration, $parameters = [], $absolute = true)
    {
        if ($name instanceof BackedEnum && ! is_string($name = $name->value)) { // @phpstan-ignore function.impossibleType
            throw new InvalidArgumentException('Attribute [name] expects a string backed enum.');
        }

        [$name, $parameters] = $this->prepareRouteInputs($name, Arr::wrap($parameters)); // @phpstan-ignore argument.type

        return parent::temporarySignedRoute($name, $expiration, $parameters, $absolute);
    }

    /**
     * Return bool indicating if the bypass parameter was in $parameters.
     */
    protected function routeBehaviorModificationBypassed(mixed $parameters): bool
    {
        if (isset($parameters[static::$bypassParameter])) {
            return (bool) $parameters[static::$bypassParameter];
        }

        return false;
    }

    /**
     * Takes a route name and an array of parameters to return the prefixed route name
     * and the route parameters with the tenant parameter added.
     *
     * To skip these modifications, pass the bypass parameter in route parameters.
     * Before returning the modified route inputs, the bypass parameter is removed from the parameters.
     */
    protected function prepareRouteInputs(string $name, array $parameters): array
    {
        if (! $this->routeBehaviorModificationBypassed($parameters)) {
            $name = $this->routeNameOverride($name) ?? $this->prefixRouteName($name);
            $parameters = $this->addTenantParameter($parameters);
        }

        // Remove bypass parameter from the route parameters
        unset($parameters[static::$bypassParameter]);

        return [$name, $parameters];
    }

    /**
     * If $prefixRouteNames is true, prefix the passed route name.
     */
    protected function prefixRouteName(string $name): string
    {
        $tenantPrefix = PathTenantResolver::tenantRouteNamePrefix();

        if (static::$prefixRouteNames && ! str($name)->startsWith($tenantPrefix)) {
            $name = str($name)->after($tenantPrefix)->prepend($tenantPrefix)->toString();
        }

        return $name;
    }

    /**
     * If `tenant()` isn't null, add the tenant parameter to the passed parameters.
     */
    protected function addTenantParameter(array $parameters): array
    {
        if (tenant() && static::$passTenantParameterToRoutes) {
            if (static::$defaultParameterNames) {
                return array_merge($parameters, ['tenant' => tenant()->getTenantKey()]);
            } else {
                return array_merge($parameters, [PathTenantResolver::tenantParameterName() => PathTenantResolver::tenantParameterValue(tenant())]);
            }
        } else {
            return $parameters;
        }
    }

    protected function routeNameOverride(string $name): string|null
    {
        return static::$overrides[$name] ?? null;
    }
}
