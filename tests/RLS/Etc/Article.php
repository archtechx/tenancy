<?php

namespace Stancl\Tenancy\Tests\RLS\Etc;

use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;
use Stancl\Tenancy\Database\Concerns\RLSModel;

/** Used for testing TraitRLSManager */
class Article extends Model implements RLSModel
{
    use BelongsToTenant;

    protected $guarded = [];
}
