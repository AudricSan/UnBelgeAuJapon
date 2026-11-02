<?php
declare(strict_types=1);

return [
    'require_https' => true,

    'security_key' => 'REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',
    'bootstrap_key' => 'REPLACE_WITH_A_DIFFERENT_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',

    'database' => [
        'dsn' => 'sqlite:/absolute/path/to/mvaj-admin.sqlite',
    ],

    'github' => [
        'owner' => 'AudricSan',
        'repository' => 'Ma-vie-au-Japon',
        'branch' => 'main',
        'token' => 'REPLACE_WITH_FINE_GRAINED_GITHUB_TOKEN',
    ],

    'analytics' => [
        'provider' => 'umami',
        'domain' => 'blog.audricrosier.be',
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
        'from_email' => 'noreply@audricrosier.be',
        'sender_name' => 'Un Belge au Japon',
        'site_url' => 'https://blog.audricrosier.be',
        'reply_to' => 'contact@audricrosier.be',
        'smtp' => [
            'host' => 'ssl0.ovh.net',
            'port' => 465,
            'username' => 'noreply@audricrosier.be',
            'password' => 'REPLACE_WITH_OVH_MXPLAN_SMTP_PASSWORD',
            'encryption' => 'ssl',
        ],
        'brevo' => [
            'api_key' => 'REPLACE_WITH_BREVO_API_KEY',
        ],
        'mailgun' => [
            'api_key' => 'REPLACE_WITH_MAILGUN_API_KEY',
            'domain' => 'mg.audricrosier.be',
        ],
        'sendgrid' => [
            'api_key' => 'REPLACE_WITH_SENDGRID_API_KEY',
        ],
    ],
];