<?php

namespace Stancl\Tenancy\Tests\Etc;

use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

/**
 * This model is intended to be used with the single-database tenancy approach.
 */
class ScopedComment extends Comment
{
    use BelongsToPrimaryModel;

    protected $table = 'comments';

    public function getRelationshipToPrimaryModel(): string
    {
        return 'post';
    }
}
