<?php

return [
    'db' => [
        'host' => '127.0.0.1',
        'username' => 'root',
        'password' => 'root',
        'database' => 'hotelsync_bridgeone',
        'port' => 3306,
    ],

    'api' => [
        'base_url' => 'https://app.otasync.me/api',
        'token' => '775580f2b13be0215b5aee08a17c7aa892ece321',
        'username' => 'YOUR_USERNAME',
        'password' => 'YOUR_PASSWORD',
        'remember' => 0,
    ],

    'logging' => [
        'file' => __DIR__ . '/logs/app.log',
    ],
];
