<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Routing\Controllers\HasMiddleware;

class HasMiddlewareController implements HasMiddleware
{
    public static function middleware()
    {
        return array_map(fn (string $middleware) => new Middleware($middleware), config('tenancy.static_identification_middleware'));
    }

    public function index()
    {
        return tenant() ? 'Tenancy is initialized.' : 'Tenancy is not initialized.';
    }
}
