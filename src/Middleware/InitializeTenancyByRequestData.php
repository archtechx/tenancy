<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Closure;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Stancl\Tenancy\Concerns\UsableWithEarlyIdentification;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedByRequestDataException;
use Stancl\Tenancy\Overrides\TenancyUrlGenerator;
use Stancl\Tenancy\Resolvers\RequestDataTenantResolver;
use Stancl\Tenancy\Tenancy;

class InitializeTenancyByRequestData extends IdentificationMiddleware
{
    use UsableWithEarlyIdentification;

    public static ?Closure $onFail = null;

    public static bool $requireCookieEncryption = false;

    public function __construct(
        protected Tenancy $tenancy,
        protected RequestDataTenantResolver $resolver,
    ) {}

    /** @return \Illuminate\Http\Response|mixed */
    public function handle(Request $request, Closure $next): mixed
    {
        if ($this->shouldBeSkipped(tenancy()->getRoute($request))) {
            // Allow accessing central route in kernel identification
            return $next($request);
        }

        // Used with *route-level* identification, takes precedence over what may have been configured for global stack middleware
        TenancyUrlGenerator::$prefixRouteNames = false;

        if ($request->method() !== 'OPTIONS') {
            return $this->initializeTenancy(
                $request,
                $next,
                $this->getPayload($request)
            );
        }

        return $next($request);
    }

    protected function getPayload(Request $request): string|null
    {
        $headerName = RequestDataTenantResolver::headerName();
        $queryParameterName = RequestDataTenantResolver::queryParameterName();
        $cookieName = RequestDataTenantResolver::cookieName();

        if ($headerName && $request->hasHeader($headerName)) {
            $payload = $request->header($headerName);
        } elseif ($queryParameterName && $request->has($queryParameterName)) {
            $payload = $request->get($queryParameterName);
        } elseif ($cookieName && $request->hasCookie($cookieName)) {
            $payload = $request->cookie($cookieName);

            if ($payload && is_string($payload)) {
                $payload = $this->getTenantFromCookie($cookieName, $payload);
            }
        } else {
            $payload = null;
        }

        if (is_string($payload) || is_null($payload)) {
            return $payload;
        }

        throw new TenantCouldNotBeIdentifiedByRequestDataException($payload);
    }

    /**
     * Check if the request has the tenant payload.
     */
    public function requestHasTenant(Request $request): bool
    {
        return (bool) $this->getPayload($request);
    }

    protected function getTenantFromCookie(string $cookieName, string $cookieValue): string|null
    {
        // If the cookie looks like it's encrypted, we try decrypting it
        if (str_starts_with($cookieValue, 'eyJpdiI')) {
            try {
                $json = base64_decode($cookieValue);
                $data = json_decode($json, true);

                if (
                    is_array($data) &&
                    isset($data['iv'], $data['value'], $data['mac'])
                ) {
                    // We can confidently assert that the cookie is encrypted. If this call were to fail, this method would just
                    // return null and the cookie payload would get skipped.
                    $cookieValue = CookieValuePrefix::validate(
                        $cookieName,
                        Crypt::decryptString($cookieValue),
                        Crypt::getAllKeys()
                    );
                }
            } catch (\Throwable) {
                // In case of any exceptions, we just use the original cookie value.
            }
        } elseif (static::$requireCookieEncryption) {
            return null;
        }

        return $cookieValue;
    }
}
