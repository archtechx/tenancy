<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Middleware;

use Illuminate\Http\Request;

class InitializeTenancyByOriginHeader extends InitializeTenancyByDomainOrSubdomain
{
    public function getDomain(Request $request): string
    {
        if ($origin = $request->header('Origin', '')) {
            return parse_url($origin, PHP_URL_HOST) ?? $origin;
        }

        return '';
    }
}
