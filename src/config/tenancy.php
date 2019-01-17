<?php

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\RedisStorageDriver',
    'database' => [
        'based_on' => 'sqlite',
        'prefix' => 'tenant',
        'suffix' => '.sqlite',
    ],
    'redis' => [
        'prefix_base' => 'tenant',
        'prefixed_connections' => [
            'default',
        ],
    ],
    'cache' => [
        'prefix_base' => 'tenant',
    ],

    'filesystem' => [
        'suffix_base' => 'tenant',
        // Disks which should be suffixed with the suffix_base + tenant UUID.
        'disks' => [
            // 'local',
            // 's3',
        ],
    ],
];
