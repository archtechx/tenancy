<?php

namespace Stancl\Tenancy\Tests\RLS\Etc;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

/**
 * Used for testing that TraitRLSManager discovers final classes.
 *
 * Uses the posts table so that it doesn't affect the number of generated policies.
 */
final class FinalPost extends Model
{
    use BelongsToTenant;

    public $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}
