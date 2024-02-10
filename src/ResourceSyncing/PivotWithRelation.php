<?php

declare(strict_types=1);

namespace Stancl\Tenancy\ResourceSyncing;

use Illuminate\Database\Eloquent\Model;

interface PivotWithRelation
{
    /**
     * E.g. return $this->users()->getModel().
     */
    public function getRelatedModel(): Model;
}
