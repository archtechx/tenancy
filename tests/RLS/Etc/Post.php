<?php

namespace Stancl\Tenancy\Tests\RLS\Etc;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\RLSModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/** Used for testing TraitRLSManager */
class Post extends Model implements RLSModel
{
    use BelongsToTenant;

    public $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}
