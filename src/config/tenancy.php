<?php

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\RedisStorageDriver',
    'tenant_route_namespace' => 'App\Http\Controllers',
    'exempt_domains' => [
        // 'localhost',
    ],
    'database' => [
        'based_on' => 'mysql',
        'prefix' => 'tenant',
        'suffix' => '',
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [
            'default',
            'cache',
        ],
    ],
    'cache' => [
        'prefix_base' => 'tenant',
    ],
    'filesystem' => [
        'suffix_base' => 'tenant',
        // Disks which should be suffixed with the suffix_base + tenant UUID.
        'disks' => [
            'local',
            // 's3',
        ],
    ],
    'database_creators' => [
        'sqlite' => 'Stancl\Tenancy\DatabaseCreators\SQLiteDatabaseCreator',
        'mysql' => 'Stancl\Tenancy\DatabaseCreators\MySQLDatabaseCreator',
    ],
    'queue_database_creation' => false,
];
