<?php

return [
    'storage_driver' => 'Stancl\Tenancy\StorageDrivers\RedisStorageDriver',
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
];
