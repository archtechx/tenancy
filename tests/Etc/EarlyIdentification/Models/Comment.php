<?php

namespace Stancl\Tenancy\Tests\Etc\EarlyIdentification\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $guarded = [];
    protected $table = 'comments';
    public $timestamps = false;

    public function post()
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
