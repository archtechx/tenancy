<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Features;

use Exception;
use Illuminate\Database\Eloquent\Model;
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

    public function bootstrap(): void
    {
        Tenancy::macro('impersonate', function (Tenant $tenant, string $userId, string $redirectUrl, string|null $authGuard = null, bool $remember = false): Model {
            return UserImpersonation::modelClass()::create([
                Tenancy::tenantKeyColumn() => $tenant->getTenantKey(),
                'user_id' => $userId,
                'redirect_url' => $redirectUrl,
                'auth_guard' => $authGuard,
                'remember' => $remember,
            ]);
        });
    }

    /** Impersonate a user and get an HTTP redirect response. */
    public static function makeResponse(#[\SensitiveParameter] string|Model $token, ?int $ttl = null): RedirectResponse
    {
        /**
         * The model does NOT have to extend ImpersonationToken, but usually it WILL be a child
         * of ImpersonationToken and this makes it clear to phpstan that the model has a redirect_url property.
         *
         * @var ImpersonationToken $token
         */
        $token = $token instanceof Model ? $token : static::modelClass()::findOrFail($token);
        $ttl ??= static::$ttl;

        $tokenExpired = $token->created_at->diffInSeconds(now()) > $ttl;

        if ($tokenExpired) {
            $token->delete();

            abort(403);
        }

        $tokenTenantId = (string) $token->getAttribute(Tenancy::tenantKeyColumn());
        $currentTenantId = (string) tenant()->getTenantKey();

        if ($tokenTenantId !== $currentTenantId) {
            $token->delete();

            abort(403);
        }

        Auth::guard($token->auth_guard)->loginUsingId($token->user_id, $token->remember);

        session()->put('tenancy_impersonation_guard', $token->auth_guard);

        $token->delete();

        return redirect($token->redirect_url);
    }

    /** @return class-string<Model> */
    public static function modelClass(): string
    {
        return config('tenancy.models.impersonation_token');
    }

    public static function isImpersonating(): bool
    {
        return session()->has('tenancy_impersonation_guard');
    }

    /**
     * Stop user impersonation by forgetting the impersonation session.
     *
     * When $logout is true, the user will also be logged out
     * from the impersonation guard stored in the session.
     *
     * Throws an exception if impersonation is not active
     * (= the impersonation guard is not in the session).
     */
    public static function stopImpersonating(bool $logout = true): void
    {
        if (! static::isImpersonating()) {
            throw new Exception('Not currently impersonating any user.');
        }

        if ($logout) {
            $guard = session()->get('tenancy_impersonation_guard');

            auth($guard)->logout();
        }

        session()->forget('tenancy_impersonation_guard');
    }
}
