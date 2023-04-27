<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function post()
    {
        return $this->belongsTo(Post::class);
    }
}
