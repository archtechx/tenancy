<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * This model is not intended to be used with the single-database tenancy approach.
 */
class Comment extends Model
{
    protected $guarded = [];

    protected $table = 'comments';

    public $timestamps = false;

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
