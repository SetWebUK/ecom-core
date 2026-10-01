<?php

return [
    'disks' => [
        // Uploaded media. public/storage is a REAL directory, never a symlink: LiteSpeed does not follow symlinks out of
        // public/, so `php artisan storage:link` must not be used (commerce:install creates the directory + .htaccess).
        'public' => [
            'driver' => 'local',
            'root' => public_path('storage'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],
    ],

    'links' => [],
];
