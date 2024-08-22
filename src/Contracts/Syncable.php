<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Contracts;

interface Syncable extends BaseSyncable
{
    public function getCentralModelFillable(): array;
}
