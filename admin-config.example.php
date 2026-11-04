<?php
declare(strict_types=1);

/**
 * Configuration privée de l'administration.
 * Copie ce fichier en admin-config.php, place-le HORS du dossier public
 * (par défaut : trois niveaux au-dessus de admin/inc/) et ne le versionne jamais.
 */
return [
    // Valeur aléatoire d'au moins 32 caractères (sert à hacher visiteurs et IP).
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'security_key' => 'REPLACE_WITH_A_RANDOM_SECRET_OF_AT_LEAST_32_CHARACTERS',

    // Compte unique de l'administration.
    //   php -r "echo password_hash('ton-mot-de-passe', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_email' => 'toi@exemple.com',
    'admin_password_hash' => 'REPLACE_WITH_PASSWORD_HASH',

    'site_url' => 'https://blog.audricrosier.be',
    'timezone' => 'Europe/Brussels',

    // Fichier SQLite (créé au premier accès) : dossier privé, inscriptible par PHP.
    'database' => [
        'dsn' => 'sqlite:' . __DIR__ . DIRECTORY_SEPARATOR . 'mvaj-admin.sqlite',
    ],

    // Jeton fine-grained limité à ce dépôt, permission « Contents : Read and write ».
    'github' => [
        'owner' => 'AudricSan',
        'repository' => 'UnBelgeAuJapon',
        'branch' => 'main',
        'token' => 'YOUR_SERVER_SIDE_FINE_GRAINED_GITHUB_TOKEN',
    ],

    'newsletter' => [
        'enabled' => true,
        'from_email' => 'noreply@audricrosier.be',
        'sender_name' => 'Un Belge au Japon',
        'smtp' => [
            'host' => 'ssl0.ovh.net',
            'port' => 465,
            'username' => 'noreply@audricrosier.be',
            'password' => 'REPLACE_WITH_SMTP_PASSWORD',
            'encryption' => 'ssl', // 'ssl' (465) ou 'tls' (587, STARTTLS)
        ],
    ],
];
