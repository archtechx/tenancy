<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Carbon\Carbon;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Exceptions\StatefulGuardRequiredException;

/**
 * @property string $token
 * @property string $tenant_id
 * @property string $user_id
 * @property string $auth_guard
 * @property string $redirect_url
 * @property bool $remember
 * @property Carbon $created_at
 */
class ImpersonationToken extends Model
{
    use CentralConnection;

    /** You can set this property to customize the table name */
    public static string $tableName = 'tenant_user_impersonation_tokens';

    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = 'token';

    public $incrementing = false;

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function getTable()
    {
        return static::$tableName;
    }

    public static function booted(): void
    {
        static::creating(function ($model) {
            $authGuard = $model->auth_guard ?? config('auth.defaults.guard');

            if (! Auth::guard($authGuard) instanceof StatefulGuard) {
                throw new StatefulGuardRequiredException($authGuard);
            }

            $model->created_at = $model->created_at ?? $model->freshTimestamp();
            $model->token = $model->token ?? Str::random(128);
            $model->auth_guard = $authGuard;
        });
    }
}
