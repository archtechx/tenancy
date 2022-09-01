<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\StatefulGuard;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Exceptions\ImpersonationTokenCouldNotBeCreatedWithNonStatefulGuard;

/**
 * @param string $token
 * @param string $tenant_id
 * @param string $user_id
 * @param string $auth_guard
 * @param string $redirect_url
 * @param Carbon $created_at
 */
class ImpersonationToken extends Model
{
    use CentralConnection;

    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = 'token';

    public $incrementing = false;

    protected $table = 'tenant_user_impersonation_tokens';

    protected $dates = [
        'created_at',
    ];

    public static function booted()
    {
        static::creating(function ($model) {
            $authGuard = $model->auth_guard ?? config('auth.defaults.guard');

            if (! Auth::guard($authGuard) instanceof StatefulGuard) {
                throw new ImpersonationTokenCouldNotBeCreatedWithNonStatefulGuard($authGuard);
            }

            $model->created_at = $model->created_at ?? $model->freshTimestamp();
            $model->token = $model->token ?? Str::random(128);
            $model->auth_guard = $authGuard;
        });
    }
}
