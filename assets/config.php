<?php

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver',
    'storage' => [
        'db' => [ // Stancl\Tenancy\StorageDrivers\DatabaseStorageDriver
            'data_column' => 'data',
            'custom_columns' => [
                // 'plan',
            ],
            'connection' => 'central',
        ],
        'redis' => [ // Stancl\Tenancy\StorageDrivers\RedisStorageDriver
            'connection' => 'tenancy',
        ],
    ],
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
        'tenancy' => false,
        'prefix_base' => 'tenant',
        'prefixed_connections' => [
            'default',
            'cache',
        ],
    ],
    'cache' => [
        'tag_base' => 'tenant',
    ],
    'filesystem' => [
        'suffix_base' => 'tenant',
        // Disks which should be suffixed with the suffix_base + tenant UUID.
        'disks' => [
            'local',
            'public',
            // 's3',
        ],
        'root_override' => [
            // Disks whose roots should be overriden after storage_path() is suffixed.
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
    ],
    'database_managers' => [
        'sqlite' => 'Stancl\Tenancy\TenantDatabaseManagers\SQLiteDatabaseManager',
        'mysql' => 'Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager',
        'pgsql' => 'Stancl\Tenancy\TenantDatabaseManagers\PostgreSQLDatabaseManager',
    ],
    'queue_database_creation' => false,
    'queue_database_deletion' => false,
    'database_name_key' => null,
    'unique_id_generator' => 'Stancl\Tenancy\UUIDGenerator',
];
