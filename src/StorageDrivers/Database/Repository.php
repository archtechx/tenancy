<?php

declare(strict_types=1);

namespace Stancl\Tenancy\StorageDrivers\Database;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/** @mixin Builder */
abstract class Repository
{
    /** @var Connection */
    public $database;

    /** @var string */
    protected $tableName;

    /** @var Builder */
    private $table;

    public function __construct(ConfigRepository $config)
    {
        $this->database = DatabaseStorageDriver::getCentralConnection();
        $this->tableName = $this->getTable($config);
        $this->table = $this->database->table($this->tableName);
    }

    public function table()
    {
        return $this->table->newQuery()->from($this->tableName);
    }

    abstract public function getTable(ConfigRepository $config);

    public function __call($method, $parameters)
    {
        return $this->table()->$method(...$parameters);
    }
}
