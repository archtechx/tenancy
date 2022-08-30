<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * @property string $token
 * @property string $tenant_id
 * @property string $user_id
 * @property string $auth_guard
 * @property string $redirect_url
 * @property Carbon $created_at
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

    public static function booted(): void
    {
        static::creating(function ($model) {
            $model->created_at = $model->created_at ?? $model->freshTimestamp();
            $model->token = $model->token ?? Str::random(128);
            $model->auth_guard = $model->auth_guard ?? config('auth.defaults.guard');
        });
    }
}
