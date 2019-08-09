<?php

namespace Stancl\Tenancy;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $dataColumn = 'data';
    protected $specialColumns = [];
    protected $guarded = [];
    protected $publicKey = 'uuid';
    public $incrementing = false;

    /**
     * Decoded data from the data column.
     *
     * @var object
     */
    private $dataObject;

    /**
     * Get data from the data column.
     *
     * @param string $key
     * @return mixed
     */
    public function getFromData(string $key)
    {
        $this->dataObject = $this->dataObject ?? json_decode($this->{$this->dataColumn});

        return $this->dataObject->$key;
    }

    /**
     * Get data from tenant storage.
     *
     * @param string $key
     * @return mixed
     */
    public function get(string $key)
    {
        return $this->$key ?? $this->getFromData($key) ?? null;
    }

    /**
     * Get multiple values from tenant storage.
     *
     * @param array $keys
     * @return array
     */
    public function getMany(array $keys): array
    {
        return array_reduce($keys, function ($keys, $key) {
            $keys[$key] = $this->get($key);

            return $keys;
        }, []);
    }

    /**
     * Put data into tenant storage.
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
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
