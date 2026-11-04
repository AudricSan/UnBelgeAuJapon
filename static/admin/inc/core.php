<?php
declare(strict_types=1);

/**
 * Noyau partagé : configuration, base SQLite, réponses JSON, session admin.
 * Utilisé par admin/api.php et par les points d'entrée publics de api/.
 */

final class ApiError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}

function fail(int $status, string $message): never
{
    throw new ApiError($status, $message);
}

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Corps JSON de la requête (tableau vide si absent ou invalide). */
function body(int $maxBytes = 1_000_000): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > $maxBytes) {
        fail(413, 'Requête trop volumineuse.');
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Les chemins de config et de base ne doivent jamais être servis par le web. */
function isInsideWebRoot(string $path): bool
{
    $root = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $real = realpath($path) ?: $path;
    if ($root === false || $root === '') {
        return false;
    }
    $norm = static fn(string $p): string => rtrim(DIRECTORY_SEPARATOR === '\\' ? strtolower($p) : $p, DIRECTORY_SEPARATOR);
    return str_starts_with($norm($real) . DIRECTORY_SEPARATOR, $norm($root) . DIRECTORY_SEPARATOR);
}

function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    // Ordre de recherche : variable d'environnement, dossier parent du site, racine du site.
    // À la racine du site, le .htaccess racine interdit tout accès web au fichier.
    $env = getenv('MVAJ_ADMIN_CONFIG');
    $candidates = is_string($env) && $env !== ''
        ? [$env]
        : [dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'admin-config.php', dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'admin-config.php'];
    $path = null;
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $path = $candidate;
            break;
        }
    }
    if ($path === null) {
        fail(503, 'Configuration absente : copie admin-config.example.php vers admin-config.php (racine du site ou dossier parent).');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        fail(503, 'Configuration invalide.');
    }
    $key = $loaded['security_key'] ?? '';
    if (!is_string($key) || strlen($key) < 32 || str_contains($key, 'REPLACE_WITH')) {
        fail(503, 'security_key doit être une valeur aléatoire d’au moins 32 caractères.');
    }
    $config = $loaded;
    return $config;
}

function siteUrl(): string
{
    $url = config()['site_url'] ?? config()['newsletter']['site_url'] ?? '';
    return rtrim(is_string($url) ? $url : '', '/');
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = config()['database']['dsn'] ?? '';
    if (!is_string($dsn) || !str_starts_with($dsn, 'sqlite:')) {
        fail(503, 'database.dsn doit être de la forme sqlite:/chemin/absolu/fichier.sqlite.');
    }
    $file = substr($dsn, 7);
    $dir = realpath(dirname($file));
    if ($dir === false || !is_writable($dir)) {
        fail(503, 'Le dossier de la base SQLite doit exister et être inscriptible.');
    }
    $file = $dir . DIRECTORY_SEPARATOR . basename($file);
    if (isInsideWebRoot($file) && !str_ends_with(strtolower($file), '.sqlite')) {
        fail(503, 'Dans le dossier du site, la base doit porter l’extension .sqlite (protégée par le .htaccess racine).');
    }
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        fail(503, 'Le pilote PHP PDO_SQLITE doit être activé.');
    }

    $pdo = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

/** Schéma versionné : ajoute une entrée à $steps pour toute évolution future. */
function migrate(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS meta (k TEXT PRIMARY KEY, v TEXT NOT NULL)');
    $version = (int) ($pdo->query("SELECT v FROM meta WHERE k = 'schema'")->fetchColumn() ?: 0);

    $steps = [
        1 => [
            'CREATE TABLE hits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ts INTEGER NOT NULL,
                day TEXT NOT NULL,
                path TEXT NOT NULL,
                lang TEXT NOT NULL DEFAULT \'\',
                ref TEXT NOT NULL DEFAULT \'\',
                visitor TEXT NOT NULL,
                device TEXT NOT NULL DEFAULT \'desktop\'
            )',
            'CREATE INDEX hits_day ON hits (day)',
            'CREATE INDEX hits_path ON hits (path, day)',
            'CREATE TABLE subscribers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                lang TEXT NOT NULL DEFAULT \'fr\',
                status TEXT NOT NULL DEFAULT \'pending\',
                token TEXT NOT NULL UNIQUE,
                created_at INTEGER NOT NULL,
                confirmed_at INTEGER,
                mailed_at INTEGER
            )',
            'CREATE TABLE campaigns (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                subject TEXT NOT NULL,
                title TEXT NOT NULL,
                url TEXT NOT NULL,
                intro TEXT NOT NULL DEFAULT \'\',
                created_at INTEGER NOT NULL
            )',
            'CREATE TABLE deliveries (
                campaign_id INTEGER NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                subscriber_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT \'pending\',
                PRIMARY KEY (campaign_id, subscriber_id)
            )',
            'CREATE TABLE throttle (bucket TEXT NOT NULL, k TEXT NOT NULL, ts INTEGER NOT NULL)',
            'CREATE INDEX throttle_idx ON throttle (bucket, k, ts)',
        ],
    ];

    foreach ($steps as $target => $statements) {
        if ($target <= $version) {
            continue;
        }
        $pdo->beginTransaction();
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
        if ($target === 1) {
            importLegacySubscribers($pdo);
        }
        $pdo->prepare("INSERT INTO meta (k, v) VALUES ('schema', ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v")->execute([(string) $target]);
        $pdo->commit();
    }
}

/** Reprend les abonnés actifs de l'ancien panneau (déjà consentants). */
function importLegacySubscribers(PDO $pdo): void
{
    $exists = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'mvaj_newsletter_subscribers'")->fetchColumn();
    if (!$exists) {
        return;
    }
    $rows = $pdo->query('SELECT email FROM mvaj_newsletter_subscribers WHERE is_active = 1')->fetchAll();
    $insert = $pdo->prepare("INSERT OR IGNORE INTO subscribers (email, lang, status, token, created_at, confirmed_at) VALUES (?, 'fr', 'active', ?, ?, ?)");
    $now = time();
    foreach ($rows as $row) {
        $insert->execute([strtolower((string) $row['email']), bin2hex(random_bytes(16)), $now, $now]);
    }
}

/** Haché non réversible d'une valeur (IP, etc.) lié à la clé secrète. */
function secretHash(string $value, string $scope = ''): string
{
    return substr(hash_hmac('sha256', $value, config()['security_key'] . $scope), 0, 32);
}

function clientIp(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

/** Limiteur simple : lève une 429 si $max événements sur $window secondes. */
function throttle(string $bucket, string $key, int $max, int $window): void
{
    $pdo = db();
    $now = time();
    $pdo->prepare('DELETE FROM throttle WHERE ts < ?')->execute([$now - 86400]);
    $count = $pdo->prepare('SELECT COUNT(*) FROM throttle WHERE bucket = ? AND k = ? AND ts > ?');
    $count->execute([$bucket, $key, $now - $window]);
    if ((int) $count->fetchColumn() >= $max) {
        fail(429, 'Trop de tentatives. Réessaie dans quelques minutes.');
    }
}

function throttleRecord(string $bucket, string $key): void
{
    db()->prepare('INSERT INTO throttle (bucket, k, ts) VALUES (?, ?, ?)')->execute([$bucket, $key, time()]);
}

/* ───────── Session admin ───────── */

function securityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; object-src 'none'; frame-ancestors 'none'; form-action 'self'");
    header('Cache-Control: no-store, private');
    if (isHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('MVAJ');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function isLoggedIn(): bool
{
    return !empty($_SESSION['admin']) && $_SESSION['admin'] === (config()['admin_email'] ?? null);
}

/** Exige une session valide, plus un jeton CSRF et la même origine pour les écritures. */
function requireAdmin(bool $write): void
{
    if (!isLoggedIn()) {
        fail(401, 'Connexion requise.');
    }
    if (!$write) {
        return;
    }
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!hash_equals((string) $_SESSION['csrf'], $token)) {
        fail(403, 'Session expirée, recharge la page.');
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = (string) parse_url($origin, PHP_URL_HOST) . ':' . (string) (parse_url($origin, PHP_URL_PORT) ?? '');
        $hostParts = explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        $selfHost = $hostParts[0] . ':' . ($hostParts[1] ?? '');
        if (strcasecmp($originHost, $selfHost) !== 0) {
            fail(403, 'Origine refusée.');
        }
    }
}

function login(string $email, string $password): void
{
    $config = config();
    $hash = $config['admin_password_hash'] ?? '';
    if (!is_string($hash) || $hash === '' || empty($config['admin_email'])) {
        fail(503, 'admin_email et admin_password_hash ne sont pas configurés.');
    }
    $ip = secretHash(clientIp(), 'login');
    throttle('login', $ip, 8, 900);

    $emailOk = hash_equals(strtolower((string) $config['admin_email']), strtolower(trim($email)));
    $passOk = password_verify($password, $hash);
    if (!$emailOk || !$passOk) {
        throttleRecord('login', $ip);
        fail(401, 'Identifiants incorrects.');
    }
    session_regenerate_id(true);
    $_SESSION['admin'] = $config['admin_email'];
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}
