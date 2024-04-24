<?php

namespace Stancl\Tenancy\Tests\RLS\Etc;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;
use Stancl\Tenancy\Database\Concerns\RLSModel;

class Comment extends Model implements RLSModel
{
    use BelongsToPrimaryModel;

    public $guarded = [];

    public $table = 'comments';

    public function getRelationshipToPrimaryModel(): string
    {
        return 'post';
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
