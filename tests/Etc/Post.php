<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * This model is intended to be used with the single-database tenancy approach.
 */
class Post extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function scoped_comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
