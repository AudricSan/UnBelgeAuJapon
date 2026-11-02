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

    'analytics' => [
        'provider' => 'umami',
        'domain' => 'blog.your-domain.com',
        'plausible' => [
            'src' => 'https://plausible.io/js/script.js',
        ],
        'umami' => [
            'src' => 'https://cloud.umami.is/script.js',
            'website_id' => 'b8aae5e6-083e-4e31-a259-75c80341a974',
        ],
        'cloudflare' => [
            'token' => 'REPLACE_WITH_CLOUDFLARE_INSIGHTS_TOKEN',
        ],
    ],

    'newsletter' => [
        'enabled' => true,
        'provider' => 'smtp',
        'from_email' => 'noreply@your-domain.com',
        'sender_name' => 'Un Belge au Japon',
        'site_url' => 'https://blog.your-domain.com',
        'reply_to' => 'contact@your-domain.com',
        'smtp' => [
            'host' => 'ssl0.ovh.net',
            'port' => 465,
            'username' => 'noreply@your-domain.com',
            'password' => 'REPLACE_WITH_OVH_MXPLAN_SMTP_PASSWORD',
            'encryption' => 'ssl',
        ],
        'brevo' => [
            'api_key' => 'REPLACE_WITH_BREVO_API_KEY',
        ],
        'mailgun' => [
            'api_key' => 'REPLACE_WITH_MAILGUN_API_KEY',
            'domain' => 'mg.your-domain.com',
        ],
        'sendgrid' => [
            'api_key' => 'REPLACE_WITH_SENDGRID_API_KEY',
        ],
    ],
];
