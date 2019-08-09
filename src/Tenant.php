<?php

namespace Stancl\Tenancy;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    public function getDataColumn()
    {
        return 'data';
    }

    public function put(string $key, $value)
    {
        if ($this->getConnection()->getSchemaBuilder()->hasColumn($this->getTable(), $key)) {
            $this->update([$key => $value]);
        } else {
            $obj = json_decode($this->{$this->getDataColumn()});
            $obj->$key = $value;

            $this->update([$this->getDataColumn() => json_encode($obj)]);
        }
    }
}
