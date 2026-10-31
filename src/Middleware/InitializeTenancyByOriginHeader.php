<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Illuminate\Http\Request;

class InitializeTenancyByOriginHeader extends InitializeTenancyByDomainOrSubdomain
{
    public function getDomain(Request $request): string
    {
        $origin = $request->header('Origin', '');

        // 'null' is the serialization of an opaque origin (RFC 6454), e.g. from sandboxed iframes or file:// pages
        if (! is_string($origin) || $origin === '' || $origin === 'null') {
            return '';
        }

        // parse_url() returns null for scheme-less values like 'foo.localhost' and false for malformed URLs like 'http://'
        $host = parse_url($origin, PHP_URL_HOST);

        if ($host === null) {
            return $origin;
        }

        return is_string($host) ? $host : '';
    }
}
