<?php

namespace Stancl\Tenancy\Tests\Etc;

use Stancl\Tenancy\Database\Concerns\BelongsToPrimaryModel;

class ScopedComment extends Comment
{
    use BelongsToPrimaryModel;

    protected $table = 'comments';

    public function getRelationshipToPrimaryModel(): string
    {
        return 'post';
    }
}
