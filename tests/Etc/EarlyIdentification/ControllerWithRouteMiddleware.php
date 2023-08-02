<?php

namespace Stancl\Tenancy\Tests\Etc\EarlyIdentification;

use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\Middleware;
use Stancl\Tenancy\Resolvers\PathTenantResolver;
use Illuminate\Routing\Controllers\HasMiddleware;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\Models\Post;
use Stancl\Tenancy\Tests\Etc\EarlyIdentification\Models\Comment;

class ControllerWithRouteMiddleware implements HasMiddleware
{
    public function __construct(
        public Service $service,
    ) {
        app()->instance('controllerRunsInTenantContext', tenancy()->initialized);
    }

    public static function middleware()
    {
        return [
            new Middleware(AdditionalMiddleware::class)
        ];
    }

    public function computePost(Post $post, Comment|null $comment = null): string
    {
        $post = $post->title;
        $comment = $comment ? '-' . $comment->comment : '';

        return $post . $comment;
    }

    public function index(): string
    {
        return $this->service->token;
    }

    public function routeHasTenantParameter(Request $request): bool
    {
        return $request->route()->hasParameter(PathTenantResolver::tenantParameterName());
    }
}
