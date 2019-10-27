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
    protected $database;

    /** @var Builder */
    protected $table;

    public function __construct(Connection $database, ConfigRepository $config)
    {
        $this->database = $database;
        $this->table = $database->table($this->getTable($config));
    }

    abstract public function getTable(ConfigRepository $config);

    public function __call($method, $parameters)
    {
        return $this->table->$method(...$parameters);
    }
}
