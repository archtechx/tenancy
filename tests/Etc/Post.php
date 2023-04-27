<?php

namespace Stancl\Tenancy\Tests\Etc;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

class Post extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public $timestamps = false;

    public function comments()
    {
        return $this->hasMany(Comment::class);
    }

    public function scoped_comments()
    {
        return $this->hasMany(Comment::class);
    }
}
