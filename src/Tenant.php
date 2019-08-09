<?php

namespace Stancl\Tenancy;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $dataColumn = 'data';
    protected $specialColumns = [];
    protected $guarded = [];

    public function put(string $key, $value)
    {
        if (array_key_exists($key, $this->specialColumns)) {
            $this->update([$key => $value]);
        } else {
            $obj = json_decode($this->{$this->dataColumn});
            $obj->$key = $value;

            $this->update([$this->getDataColumn() => json_encode($obj)]);
        }

        return $value;
    }
}
