<?php
declare(strict_types=1);

return [
    // Keep the real admin-config.php outside the public web root and out of Git.
    'require_https' => true,

    // Generate two different random values of at least 32 characters.
    // The bootstrap key is used only to create the first administrator.
    'security_key' => 'REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',
    'bootstrap_key' => 'REPLACE_WITH_A_DIFFERENT_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',

    'database' => [
        // Keep this file outside the public web root. PHP creates the SQLite file here.
        'dsn' => 'sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . 'mvaj-admin.sqlite',
    ],

    'github' => [
        'owner' => 'AudricSan',
        'repository' => 'Ma-vie-au-Japon',
        'branch' => 'main',
        // Fine-grained token: only this repository, Contents read/write.
        'token' => 'YOUR_SERVER_SIDE_FINE_GRAINED_GITHUB_TOKEN',
    ],
];
