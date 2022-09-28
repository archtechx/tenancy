<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Stancl\Tenancy\Contracts\Feature;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Database\Models\ImpersonationToken;
use Stancl\Tenancy\Tenancy;

class UserImpersonation implements Feature
{
    /** The lifespan of impersonation tokens (in seconds). */
    public static int $ttl = 60;

    public function bootstrap(Tenancy $tenancy): void
    {
        $tenancy->macro('impersonate', function (Tenant $tenant, string $userId, string $redirectUrl, string $authGuard = null): ImpersonationToken {
            return ImpersonationToken::create([
                'tenant_id' => $tenant->getTenantKey(),
                'user_id' => $userId,
                'redirect_url' => $redirectUrl,
                'auth_guard' => $authGuard,
            ]);
        });
    }

    /** Impersonate a user and get an HTTP redirect response. */
    public static function makeResponse(string|ImpersonationToken $token, int $ttl = null): RedirectResponse
    {
        /** @var ImpersonationToken $token */
        $token = $token instanceof ImpersonationToken ? $token : ImpersonationToken::findOrFail($token);
        $ttl ??= static::$ttl;

        $tokenExpired = $token->created_at->diffInSeconds(now()) > $ttl;

        abort_if($tokenExpired, 403);

        $tokenTenantId = (string) $token->tenant_id;
        $currentTenantId = (string) tenant()->getTenantKey();

        abort_unless($tokenTenantId === $currentTenantId, 403);

        Auth::guard($token->auth_guard)->loginUsingId($token->user_id);

        $token->delete();

        return redirect($token->redirect_url);
    }
}
