<?php

namespace Stancl\Tenancy\Tests\Etc\EarlyIdentification\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $guarded = [];
    protected $table = 'posts';
    public $timestamps = false;

    public function comments()
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
