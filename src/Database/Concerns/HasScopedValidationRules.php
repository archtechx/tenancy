<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Database\Concerns;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;

trait HasScopedValidationRules
{
    public function unique($table, $column = 'NULL')
    {
        return (new Unique($table, $column))->where(config('tenancy.single_db.tenant_id_column'), $this->getTenantKey());
    }

    public function exists($table, $column = 'NULL')
    {
        return (new Exists($table, $column))->where(config('tenancy.single_db.tenant_id_column'), $this->getTenantKey());
    }
}
