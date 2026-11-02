<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
adminSecurityHeaders();

final class AdminApiException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus, string $message)
    {
        parent::__construct($message);
    }
}

function apiReply(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apiFail(int $status, string $message): never
{
    throw new AdminApiException($status, $message);
}

function adminPathIsInDocumentRoot(string $path, string $documentRoot): bool
{
    $normalizedPath = rtrim(realpath($path) ?: $path, DIRECTORY_SEPARATOR);
    $normalizedRoot = rtrim(realpath($documentRoot) ?: $documentRoot, DIRECTORY_SEPARATOR);
    if ($normalizedPath === '' || $normalizedRoot === '') {
        return false;
    }
    $pathToCheck = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedPath) : $normalizedPath;
    $rootToCheck = DIRECTORY_SEPARATOR === '\\' ? strtolower($normalizedRoot) : $normalizedRoot;
    if ($pathToCheck === $rootToCheck) {
        return true;
    }
    return str_starts_with($pathToCheck, $rootToCheck . DIRECTORY_SEPARATOR);
}

function loadAdminConfig(): array
{
    static $loaded = null;
    if (is_array($loaded)) {
        return $loaded;
    }

    $configuredPath = getenv('MVAJ_ADMIN_CONFIG');
    $path = is_string($configuredPath) && $configuredPath !== ''
        ? $configuredPath
        : dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'admin-config.php';

    if (!is_file($path) || !is_readable($path)) {
        apiFail(503, 'Le panneau d’administration n’est pas encore configuré.');
    }
    $realPath = realpath($path);
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($realPath !== false && $documentRoot !== false) {
        $configDir = dirname($realPath);
        $configIsAtDocumentRoot = $configDir === $documentRoot;
        $configIsInsideDocumentRootSubdirectory = ! $configIsAtDocumentRoot && adminPathIsInDocumentRoot($realPath, $documentRoot);
        if ($configIsInsideDocumentRootSubdirectory) {
            apiFail(503, 'Le fichier de configuration doit être placé hors du dossier public.');
        }
    }

    $config = require $path;
    if (!is_array($config)) {
        apiFail(503, 'La configuration du panneau d’administration est invalide.');
    }

    $required = ['database', 'github', 'bootstrap_key'];
    foreach ($required as $key) {
        if (!array_key_exists($key, $config)) {
            apiFail(503, 'La configuration du panneau d’administration est incomplète.');
        }
    }
    if (!is_array($config['database']) || !is_array($config['github'])) {
        apiFail(503, 'La configuration du panneau d’administration est invalide.');
    }
    $securityKey = $config['security_key'] ?? '';
    if (!is_string($securityKey) || strlen($securityKey) < 32 || str_contains($securityKey, 'REPLACE_WITH_')) {
        apiFail(503, 'La clé de sécurité du panneau d’administration doit être remplacée par une valeur aléatoire.');
    }

    $loaded = $config;
    return $loaded;
}

function adminDatabase(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = $config['database'];
    $dsn = $database['dsn'] ?? null;
    if (!is_string($dsn) || !str_starts_with($dsn, 'sqlite:')) {
        apiFail(503, 'Configure un fichier SQLite pour le panneau d’administration.');
    }

    $databasePath = substr($dsn, strlen('sqlite:'));
    $isAbsolutePath = str_starts_with($databasePath, DIRECTORY_SEPARATOR)
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $databasePath) === 1;
    if (!$isAbsolutePath || $databasePath === ':memory:' || $databasePath === '') {
        apiFail(503, 'Le chemin SQLite doit désigner un fichier persistant avec un chemin absolu.');
    }
    $databaseDirectory = realpath(dirname($databasePath));
    if ($databaseDirectory === false || !is_dir($databaseDirectory) || !is_writable($databaseDirectory)) {
        apiFail(503, 'Le dossier qui contient la base SQLite doit exister et être accessible en écriture par PHP.');
    }
    $databasePath = $databaseDirectory . DIRECTORY_SEPARATOR . basename($databasePath);
    $resolvedDatabasePath = realpath($databasePath) ?: $databasePath;
    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot !== false) {
        $databaseDirectory = dirname($resolvedDatabasePath);
        $databaseIsAtDocumentRoot = $databaseDirectory === $documentRoot;
        $databaseIsInsideDocumentRootSubdirectory = ! $databaseIsAtDocumentRoot && adminPathIsInDocumentRoot($resolvedDatabasePath, $documentRoot);
        if ($databaseIsInsideDocumentRootSubdirectory) {
            apiFail(503, 'Le fichier SQLite doit être placé hors du dossier public.');
        }
    }

    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        apiFail(503, 'Le pilote PHP PDO_SQLITE doit être activé.');
    }

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    if (DIRECTORY_SEPARATOR !== '\\' && is_file($databasePath)) {
        @chmod($databasePath, 0600);
    }
    return $pdo;
}

function ensureAdminSchema(PDO $pdo): void
{
    $tableCount = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('mvaj_admin_users', 'mvaj_admin_user_roles', 'mvaj_admin_login_attempts', 'mvaj_analytics_events', 'mvaj_newsletter_subscribers', 'mvaj_newsletter_sends')")->fetchColumn();
    if ($tableCount === 6) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS mvaj_analytics_events (id INTEGER PRIMARY KEY AUTOINCREMENT, path TEXT NOT NULL, page_title TEXT DEFAULT '', language TEXT DEFAULT 'fr', referrer TEXT DEFAULT '', user_agent_hash TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mvaj_newsletter_subscribers (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, source TEXT DEFAULT 'site', is_active INTEGER NOT NULL DEFAULT 1, consent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, unsubscribed_at TEXT NULL)");
        $pdo->exec("CREATE TABLE IF NOT EXISTS mvaj_newsletter_sends (id INTEGER PRIMARY KEY AUTOINCREMENT, article_slug TEXT NOT NULL, article_title TEXT NOT NULL, language TEXT NOT NULL DEFAULT 'fr', recipients_count INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT 'sent', sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)");
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mvaj_analytics_path_created ON mvaj_analytics_events (path, created_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mvaj_newsletter_subscribers_active ON mvaj_newsletter_subscribers (is_active, created_at)');
        return;
    }

    $statements = [
        "CREATE TABLE IF NOT EXISTS mvaj_admin_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            display_name TEXT NOT NULL,
            password_hash TEXT NOT NULL,
            must_change_password INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_login_at TEXT NULL
        )",
        "CREATE TABLE IF NOT EXISTS mvaj_admin_user_roles (
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            PRIMARY KEY (user_id, role),
            FOREIGN KEY (user_id) REFERENCES mvaj_admin_users(id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS mvaj_admin_login_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash TEXT NOT NULL,
            email_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS mvaj_analytics_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            path TEXT NOT NULL,
            page_title TEXT DEFAULT '',
            language TEXT DEFAULT 'fr',
            referrer TEXT DEFAULT '',
            user_agent_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        "CREATE TABLE IF NOT EXISTS mvaj_newsletter_subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT NOT NULL UNIQUE,
            source TEXT DEFAULT 'site',
            is_active INTEGER NOT NULL DEFAULT 1,
            consent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            unsubscribed_at TEXT NULL
        )",
        "CREATE TABLE IF NOT EXISTS mvaj_newsletter_sends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            article_slug TEXT NOT NULL,
            article_title TEXT NOT NULL,
            language TEXT NOT NULL DEFAULT 'fr',
            recipients_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'sent',
            sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
        'CREATE INDEX IF NOT EXISTS idx_mvaj_admin_attempts_ip_time ON mvaj_admin_login_attempts (ip_hash, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_mvaj_admin_attempts_email_time ON mvaj_admin_login_attempts (email_hash, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_mvaj_analytics_path_created ON mvaj_analytics_events (path, created_at)',
        'CREATE INDEX IF NOT EXISTS idx_mvaj_newsletter_subscribers_active ON mvaj_newsletter_subscribers (is_active, created_at)',
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
}

function adminRequestBody(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 550000) {
        apiFail(413, 'La requête est trop volumineuse.');
    }

    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    try {
        $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiFail(400, 'Le format de la requête est invalide.');
    }
    if (!is_array($body)) {
        apiFail(400, 'Le format de la requête est invalide.');
    }
    return $body;
}

function adminCheckOrigin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!is_string($origin) || $origin === '') {
        return;
    }
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $requestHost = strtolower((string) parse_url('https://' . (string) ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $originScheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
    $requestScheme = adminIsHttps() ? 'https' : 'http';
    if ($originHost === '' || $originHost !== $requestHost || $originScheme !== $requestScheme) {
        apiFail(403, 'Origine de requête invalide.');
    }
}

function adminRequireCsrf(): void
{
    adminCheckOrigin();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !isset($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
        apiFail(403, 'La session a expiré. Recharge la page et réessaie.');
    }
}

function adminRotateSession(): void
{
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function adminEnsurePassword(string $password): void
{
    if (strlen($password) < 14 || strlen($password) > 200 || str_contains($password, "\0")) {
        apiFail(400, 'Le mot de passe doit contenir entre 14 et 200 caractères.');
    }
}

function adminPasswordHash(string $password): string
{
    $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    $hash = password_hash($password, $algorithm);
    if (!is_string($hash)) {
        apiFail(500, 'Impossible d’enregistrer le mot de passe.');
    }
    return $hash;
}

function adminRoles(): array
{
    return ['editor', 'translator', 'admin'];
}

function adminValidateRoles(mixed $roles): array
{
    if (!is_array($roles)) {
        apiFail(400, 'La liste des rôles est invalide.');
    }
    $clean = [];
    foreach ($roles as $role) {
        if (!is_string($role) || !in_array($role, adminRoles(), true)) {
            apiFail(400, 'Un rôle sélectionné est invalide.');
        }
        $clean[] = $role;
    }
    $clean = array_values(array_unique($clean));
    if ($clean === []) {
        apiFail(400, 'Attribue au moins un rôle au compte.');
    }
    return $clean;
}

function adminUser(PDO $pdo): ?array
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        return null;
    }
    $query = $pdo->prepare('SELECT id, email, display_name, must_change_password, is_active FROM mvaj_admin_users WHERE id = ? LIMIT 1');
    $query->execute([$userId]);
    $user = $query->fetch();
    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }
    $rolesQuery = $pdo->prepare('SELECT role FROM mvaj_admin_user_roles WHERE user_id = ? ORDER BY role');
    $rolesQuery->execute([$userId]);
    $user['roles'] = array_column($rolesQuery->fetchAll(), 'role');
    $user['id'] = (int) $user['id'];
    $user['must_change_password'] = (bool) $user['must_change_password'];
    unset($user['is_active']);
    return $user;
}

function adminRequireUser(PDO $pdo, bool $allowPasswordChangeOnly = false): array
{
    $user = adminUser($pdo);
    if ($user === null) {
        apiFail(401, 'Connecte-toi pour continuer.');
    }
    if ($user['must_change_password'] && !$allowPasswordChangeOnly) {
        apiFail(403, 'Change ton mot de passe temporaire avant de continuer.');
    }
    return $user;
}

function adminRequireRole(PDO $pdo, array $allowedRoles): array
{
    $user = adminRequireUser($pdo);
    if (!in_array('admin', $user['roles'], true) && array_intersect($allowedRoles, $user['roles']) === []) {
        apiFail(403, 'Tu n’as pas le rôle nécessaire pour cette action.');
    }
    return $user;
}

function adminUserCount(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM mvaj_admin_users')->fetchColumn();
}

function adminEmail(string $email): string
{
    $email = strtolower(trim($email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiFail(400, 'Saisis une adresse e-mail valide.');
    }
    return $email;
}

function adminDisplayName(string $name): string
{
    $name = trim($name);
    $characterCount = preg_match_all('/./us', $name);
    if ($name === '' || $characterCount === false || $characterCount > 100) {
        apiFail(400, 'Le nom doit contenir entre 1 et 100 caractères.');
    }
    if (preg_match('/[\\x00-\\x1F\\x7F]/u', $name) === 1) {
        apiFail(400, 'Le nom contient des caractères non autorisés.');
    }
    return $name;
}

function adminRateLimitHashes(string $email, array $config): array
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $key = is_string($config['security_key'] ?? null) ? $config['security_key'] : '';
    if (strlen($key) < 32) {
        apiFail(503, 'La clé de sécurité du panneau d’administration doit être configurée.');
    }
    return [
        hash_hmac('sha256', $ip, $key),
        hash_hmac('sha256', strtolower(trim($email)), $key),
    ];
}

function adminCheckLoginRate(PDO $pdo, string $ipHash, string $emailHash): void
{
    $query = $pdo->prepare("SELECT SUM(CASE WHEN ip_hash = ? THEN 1 ELSE 0 END) AS ip_count, SUM(CASE WHEN ip_hash = ? AND email_hash = ? THEN 1 ELSE 0 END) AS pair_count FROM mvaj_admin_login_attempts WHERE created_at >= datetime('now', '-15 minutes')");
    $query->execute([$ipHash, $ipHash, $emailHash]);
    $counts = $query->fetch() ?: [];
    if ((int) ($counts['ip_count'] ?? 0) >= 12 || (int) ($counts['pair_count'] ?? 0) >= 6) {
        apiFail(429, 'Trop de tentatives. Réessaie dans quelques minutes.');
    }
}

function adminRecordLoginFailure(PDO $pdo, string $ipHash, string $emailHash): void
{
    $query = $pdo->prepare('INSERT INTO mvaj_admin_login_attempts (ip_hash, email_hash) VALUES (?, ?)');
    $query->execute([$ipHash, $emailHash]);
    $pdo->exec("DELETE FROM mvaj_admin_login_attempts WHERE created_at < datetime('now', '-1 day')");
}

function adminGithubConfig(array $config): array
{
    $github = $config['github'];
    foreach (['owner', 'repository', 'branch', 'token'] as $key) {
        if (!isset($github[$key]) || !is_string($github[$key]) || trim($github[$key]) === '') {
            apiFail(503, 'La connexion au dépôt GitHub n’est pas configurée.');
        }
    }
    if (!preg_match('/^[A-Za-z0-9-]+$/', $github['owner']) || !preg_match('/^[A-Za-z0-9._-]+$/', $github['repository'])) {
        apiFail(503, 'La configuration du dépôt GitHub est invalide.');
    }
    if (str_starts_with($github['token'], 'YOUR_')) {
        apiFail(503, 'Le jeton GitHub du serveur doit être configuré.');
    }
    return $github;
}

function adminGithubRequest(array $config, string $method, string $endpoint, ?array $payload = null): array
{
    if (!function_exists('curl_init')) {
        apiFail(503, 'L’extension PHP cURL doit être activée pour le dépôt GitHub.');
    }
    $github = adminGithubConfig($config);
    $url = 'https://api.github.com/repos/' . rawurlencode($github['owner']) . '/' . rawurlencode($github['repository']) . $endpoint;
    $headers = [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $github['token'],
        'Content-Type: application/json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: MaVieAuJapon-Admin',
    ];
    $curl = curl_init($url);
    if ($curl === false) {
        apiFail(502, 'Connexion à GitHub impossible.');
    }
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($payload !== null) {
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $encoded);
    }
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $curlError = curl_errno($curl);
    curl_close($curl);
    if (!is_string($response) || $curlError !== 0) {
        apiFail(502, 'Connexion à GitHub impossible.');
    }
    try {
        $data = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        apiFail(502, 'GitHub a renvoyé une réponse invalide.');
    }
    if (!is_array($data)) {
        apiFail(502, 'GitHub a renvoyé une réponse invalide.');
    }
    return ['status' => $status, 'data' => $data];
}

function adminGithubPath(string $path): string
{
    return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function adminGithubFile(array $config, string $path): ?array
{
    $github = adminGithubConfig($config);
    $endpoint = '/contents/' . adminGithubPath($path) . '?ref=' . rawurlencode($github['branch']);
    $result = adminGithubRequest($config, 'GET', $endpoint);
    if ($result['status'] === 404) {
        return null;
    }
    if ($result['status'] < 200 || $result['status'] >= 300) {
        apiFail(502, 'Lecture du fichier dans GitHub impossible.');
    }
    return $result['data'];
}

function adminGithubFileText(array $config, string $path): ?string
{
    $file = adminGithubFile($config, $path);
    if ($file === null) {
        return null;
    }
    if (($file['type'] ?? '') !== 'file' || !isset($file['content']) || !is_string($file['content'])) {
        apiFail(502, 'Le fichier demandé ne peut pas être lu.');
    }
    $content = base64_decode(str_replace("\n", '', $file['content']), true);
    if (!is_string($content)) {
        apiFail(502, 'Le fichier du dépôt est invalide.');
    }
    return $content;
}

function adminGithubWriteFile(array $config, string $path, string $content, string $message, bool $allowUpdate): void
{
    $github = adminGithubConfig($config);
    $existing = adminGithubFile($config, $path);
    if ($existing !== null && !$allowUpdate) {
        apiFail(409, 'Cet article existe déjà. Choisis un autre slug.');
    }
    if ($existing !== null && !isset($existing['sha'])) {
        apiFail(502, 'GitHub n’a pas fourni la version actuelle du fichier.');
    }
    $payload = [
        'message' => $message,
        'content' => base64_encode($content),
        'branch' => $github['branch'],
    ];
    if ($existing !== null) {
        $payload['sha'] = $existing['sha'];
    }
    $endpoint = '/contents/' . adminGithubPath($path);
    $result = adminGithubRequest($config, 'PUT', $endpoint, $payload);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        apiFail(502, 'Enregistrement dans GitHub impossible. Réessaie dans un instant.');
    }
}

function adminGithubHead(array $config): array
{
    $github = adminGithubConfig($config);
    $branch = rawurlencode($github['branch']);
    $ref = adminGithubRequest($config, 'GET', '/git/ref/heads/' . $branch);
    if ($ref['status'] < 200 || $ref['status'] >= 300 || !isset($ref['data']['object']['sha'])) {
        apiFail(502, 'Lecture de la branche GitHub impossible.');
    }
    $commitSha = (string) $ref['data']['object']['sha'];
    $commit = adminGithubRequest($config, 'GET', '/git/commits/' . rawurlencode($commitSha));
    if ($commit['status'] < 200 || $commit['status'] >= 300 || !isset($commit['data']['tree']['sha'])) {
        apiFail(502, 'Lecture de la branche GitHub impossible.');
    }
    return ['commit' => $commitSha, 'tree' => (string) $commit['data']['tree']['sha']];
}

function adminGithubTree(array $config): array
{
    $head = adminGithubHead($config);
    $result = adminGithubRequest($config, 'GET', '/git/trees/' . rawurlencode($head['tree']) . '?recursive=1');
    if ($result['status'] < 200 || $result['status'] >= 300 || !isset($result['data']['tree']) || !is_array($result['data']['tree'])) {
        apiFail(502, 'Lecture de la liste des articles impossible.');
    }
    if (($result['data']['truncated'] ?? false) === true) {
        apiFail(502, 'Le dépôt est trop volumineux pour afficher les articles.');
    }
    return $result['data']['tree'];
}

function adminArticleSection(mixed $value): string
{
    $allowed = ['histoire', 'info', 'quotidien'];
    if (!is_string($value) || !in_array($value, $allowed, true)) {
        apiFail(400, 'La rubrique choisie est invalide.');
    }
    return $value;
}

function adminArticleSlug(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value)) {
        apiFail(400, 'Le slug doit utiliser des minuscules, des chiffres et des tirets.');
    }
    return $value;
}

function adminArticleDate(mixed $value): string
{
    if (!is_string($value)) {
        apiFail(400, 'La date de l’article est invalide.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        apiFail(400, 'Saisis une date valide au format AAAA-MM-JJ.');
    }
    return $value;
}

function adminArticleText(mixed $value, string $label, int $max, bool $required = true): string
{
    if (!is_string($value)) {
        apiFail(400, $label . ' est invalide.');
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    if (($required && $value === '') || strlen($value) > $max || str_contains($value, "\0")) {
        apiFail(400, $label . ' est obligatoire et ne peut pas dépasser la limite autorisée.');
    }
    return $value;
}

function adminArticleList(mixed $value, string $label): array
{
    if (!is_array($value) || count($value) > 40) {
        apiFail(400, 'La liste « ' . $label . ' » est invalide.');
    }
    $clean = [];
    foreach ($value as $item) {
        if (!is_string($item)) {
            apiFail(400, 'La liste « ' . $label . ' » est invalide.');
        }
        $item = trim($item);
        if ($item !== '') {
            if (strlen($item) > 120 || preg_match('/[\\x00-\\x1F\\x7F]/u', $item) === 1) {
                apiFail(400, 'Un élément de « ' . $label . ' » est trop long.');
            }
            $clean[] = $item;
        }
    }
    return array_values(array_unique($clean));
}

function adminYamlString(string $value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

function adminYamlList(string $name, array $values): string
{
    if ($values === []) {
        return $name . ': []';
    }
    return $name . ":\n" . implode("\n", array_map(static fn(string $value): string => '  - ' . adminYamlString($value), $values));
}

function adminArticleMarkdown(array $input): string
{
    $title = adminArticleText($input['title'] ?? null, 'Le titre', 500);
    $date = adminArticleDate($input['date'] ?? null);
    $description = adminArticleText($input['description'] ?? null, 'Le résumé', 2000);
    $keywords = adminArticleList($input['keywords'] ?? [], 'mots-clés');
    $categories = adminArticleList($input['categories'] ?? [], 'catégories');
    $tags = adminArticleList($input['tags'] ?? [], 'étiquettes');
    $body = adminArticleText($input['body'] ?? null, 'Le contenu', 500000);
    $draft = ($input['draft'] ?? false) === true;

    return "---\n"
        . 'title: ' . adminYamlString($title) . "\n"
        . 'date: ' . $date . "\n"
        . "author: \"AudricSan\"\n"
        . 'description: ' . adminYamlString($description) . "\n"
        . adminYamlList('keywords', $keywords) . "\n"
        . adminYamlList('categories', $categories) . "\n"
        . adminYamlList('tags', $tags) . "\n"
        . 'slug: ' . adminYamlString((string) $input['slug']) . "\n"
        . 'draft: ' . ($draft ? 'true' : 'false') . "\n"
        . "---\n\n"
        . $body . "\n";
}

function adminArticlePath(string $section, string $slug, string $language): string
{
    return 'content/Blog/' . $section . '/' . $slug . '/index.' . $language . '.md';
}

function adminCreateArticle(array $config, array $input): void
{
    $section = adminArticleSection($input['section'] ?? null);
    $slug = adminArticleSlug($input['slug'] ?? null);
    $input['slug'] = $slug;
    $path = adminArticlePath($section, $slug, 'fr');
    $markdown = adminArticleMarkdown($input);
    adminGithubWriteFile($config, $path, $markdown, 'Créer article ' . $slug . ' (fr)', false);
}

function adminTranslateArticle(array $config, array $input): void
{
    $section = adminArticleSection($input['section'] ?? null);
    $slug = adminArticleSlug($input['slug'] ?? null);
    $sourcePath = adminArticlePath($section, $slug, 'fr');
    $source = adminGithubFileText($config, $sourcePath);
    if ($source === null) {
        apiFail(404, 'L’article français correspondant est introuvable.');
    }
    $input['draft'] = preg_match('/^draft:\\s*true\\s*$/mi', $source) === 1;
    $input['slug'] = $slug;
    $path = adminArticlePath($section, $slug, 'ja');
    $markdown = adminArticleMarkdown($input);
    adminGithubWriteFile($config, $path, $markdown, 'Traduire article ' . $slug . ' (ja)', true);
}

function adminDeleteArticle(array $config, string $section, string $slug): int
{
    $prefix = 'content/Blog/' . $section . '/' . $slug . '/';
    $paths = [];
    foreach (adminGithubTree($config) as $node) {
        $path = $node['path'] ?? null;
        if (is_string($path) && str_starts_with($path, $prefix) && ($node['type'] ?? '') === 'blob') {
            $paths[] = $path;
        }
    }
    if ($paths === []) {
        apiFail(404, 'Aucun fichier trouvé pour cet article.');
    }

    $github = adminGithubConfig($config);
    $head = adminGithubHead($config);
    $entries = array_map(static fn(string $path): array => [
        'path' => $path,
        'mode' => '100644',
        'type' => 'blob',
        'sha' => null,
    ], $paths);
    $newTree = adminGithubRequest($config, 'POST', '/git/trees', [
        'base_tree' => $head['tree'],
        'tree' => $entries,
    ]);
    if ($newTree['status'] < 200 || $newTree['status'] >= 300 || !isset($newTree['data']['sha'])) {
        apiFail(502, 'La suppression de l’article n’a pas pu être préparée.');
    }
    $commit = adminGithubRequest($config, 'POST', '/git/commits', [
        'message' => 'Supprimer article ' . $slug,
        'tree' => $newTree['data']['sha'],
        'parents' => [$head['commit']],
    ]);
    if ($commit['status'] < 200 || $commit['status'] >= 300 || !isset($commit['data']['sha'])) {
        apiFail(502, 'La suppression de l’article n’a pas pu être enregistrée.');
    }
    $ref = adminGithubRequest($config, 'PATCH', '/git/refs/heads/' . rawurlencode($github['branch']), [
        'sha' => $commit['data']['sha'],
        'force' => false,
    ]);
    if ($ref['status'] < 200 || $ref['status'] >= 300) {
        apiFail(409, 'La branche a changé pendant la suppression. Recharge la liste et réessaie.');
    }
    return count($paths);
}

function adminNewsletterSummary(PDO $pdo): array
{
    $totalSubscribers = (int) $pdo->query("SELECT COUNT(*) FROM mvaj_newsletter_subscribers WHERE is_active = 1")->fetchColumn();
    $totalEvents = (int) $pdo->query("SELECT COUNT(*) FROM mvaj_analytics_events")->fetchColumn();
    $lastWeek = (int) $pdo->query("SELECT COUNT(*) FROM mvaj_analytics_events WHERE created_at >= datetime('now', '-7 days')")->fetchColumn();
    $topPageRows = $pdo->query("SELECT path, COUNT(*) AS hits FROM mvaj_analytics_events GROUP BY path ORDER BY hits DESC, path ASC LIMIT 8")->fetchAll();
    return [
        'total_subscribers' => $totalSubscribers,
        'total_events' => $totalEvents,
        'week_events' => $lastWeek,
        'top_pages' => array_map(static fn(array $row): array => ['path' => (string) $row['path'], 'hits' => (int) $row['hits']], $topPageRows),
    ];
}

function adminNewsletterBuildEmail(array $newsletter, string $toEmail, string $title, string $siteUrl): array
{
    $fromEmail = is_string($newsletter['from_email'] ?? null) ? trim((string) $newsletter['from_email']) : 'newsletter@localhost';
    $fromName = is_string($newsletter['sender_name'] ?? null) ? trim((string) $newsletter['sender_name']) : 'Un Belge au Japon';
    $unsubscribeUrl = rtrim($siteUrl, '/') . '/newsletter/unsubscribe.php?email=' . rawurlencode($toEmail);
    $subject = 'Nouvel article : ' . $title;
    $plainText = "Bonjour,\n\nUn nouvel article vient d'être publié sur Un Belge au Japon :\n\n« {$title} »\n\nConsultez-le ici : {$siteUrl}\n\nSe désinscrire : {$unsubscribeUrl}\n\nÀ bientôt,\n{$fromName}\n\n---\nVous recevez cet email parce que vous êtes abonné·e à la newsletter.";
    $html = sprintf(
        '<html><body style="font-family:Arial,sans-serif;line-height:1.6;color:#0f172a;">'
        . '<p>Bonjour,</p>'
        . '<p>Un nouvel article vient d’être publié sur <strong>Un Belge au Japon</strong> :</p>'
        . '<p><strong>%s</strong></p>'
        . '<p><a href="%s">Lire l’article</a></p>'
        . '<p><a href="%s">Se désinscrire</a></p>'
        . '<p>À bientôt,<br>%s</p>'
        . '</body></html>',
        htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($unsubscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        htmlspecialchars($fromName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
    );

    return ['subject' => $subject, 'plain' => $plainText, 'html' => $html, 'from_email' => $fromEmail, 'from_name' => $fromName, 'unsubscribe_url' => $unsubscribeUrl];
}

function adminNewsletterSendWithProvider(array $newsletter, string $toEmail, string $subject, string $plainText, string $htmlText, string $fromEmail, string $fromName, string $unsubscribeUrl = ''): bool
{
    $provider = strtolower((string) ($newsletter['provider'] ?? 'smtp'));

    if ($provider === 'smtp') {
        $smtp = is_array($newsletter['smtp'] ?? null) ? $newsletter['smtp'] : [];
        $host = is_string($smtp['host'] ?? null) ? trim((string) $smtp['host']) : 'ssl0.ovh.net';
        $port = isset($smtp['port']) ? (int) $smtp['port'] : 465;
        $username = is_string($smtp['username'] ?? null) ? trim((string) $smtp['username']) : '';
        $password = is_string($smtp['password'] ?? null) ? (string) $smtp['password'] : '';
        $encryption = strtolower((string) ($smtp['encryption'] ?? 'ssl'));

        if ($host === '' || $username === '' || $password === '') {
            return false;
        }

        $transport = ($encryption === 'ssl') ? 'ssl://' . $host : $host;
        $socket = @fsockopen($transport, $port, $errno, $errstr, 30);
        if ($socket === false) {
            return false;
        }
        stream_set_timeout($socket, 30);

        $readResponse = static function ($stream): string {
            $response = '';
            while (!feof($stream)) {
                $line = fgets($stream, 515);
                if ($line === false) {
                    break;
                }
                $response .= $line;
                if (strlen($line) >= 3 && $line[3] === ' ') {
                    break;
                }
            }
            return trim($response);
        };

        $banner = $readResponse($socket);
        if (strpos($banner, '220') !== 0) {
            fclose($socket);
            return false;
        }

        fwrite($socket, "EHLO " . $host . "\r\n");
        $ehlo = $readResponse($socket);
        if (strpos($ehlo, '250') !== 0) {
            fclose($socket);
            return false;
        }

        if ($encryption === 'tls') {
            fwrite($socket, "STARTTLS\r\n");
            $tlsAnswer = $readResponse($socket);
            if (strpos($tlsAnswer, '220') !== 0) {
                fclose($socket);
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return false;
            }
            fwrite($socket, "EHLO " . $host . "\r\n");
            $readResponse($socket);
        }

        fwrite($socket, "AUTH LOGIN\r\n");
        $authPrompt = $readResponse($socket);
        if (strpos($authPrompt, '334') !== 0) {
            fclose($socket);
            return false;
        }
        fwrite($socket, base64_encode($username) . "\r\n");
        $userPrompt = $readResponse($socket);
        if (strpos($userPrompt, '334') !== 0) {
            fclose($socket);
            return false;
        }
        fwrite($socket, base64_encode($password) . "\r\n");
        $authResult = $readResponse($socket);
        if (strpos($authResult, '235') !== 0) {
            fclose($socket);
            return false;
        }

        fwrite($socket, "MAIL FROM:<" . $fromEmail . ">\r\n");
        $mailFromStatus = $readResponse($socket);
        if (strpos($mailFromStatus, '250') !== 0) {
            fclose($socket);
            return false;
        }
        fwrite($socket, "RCPT TO:<" . $toEmail . ">\r\n");
        $rcptStatus = $readResponse($socket);
        if (strpos($rcptStatus, '250') !== 0 && strpos($rcptStatus, '251') !== 0) {
            fclose($socket);
            return false;
        }
        fwrite($socket, "DATA\r\n");
        $dataStatus = $readResponse($socket);
        if (strpos($dataStatus, '354') !== 0) {
            fclose($socket);
            return false;
        }

        $headers = [
            'From: ' . $fromName . ' <' . $fromEmail . '>',
            'To: ' . $toEmail,
            'Subject: ' . $subject,
            'Reply-To: ' . $fromEmail,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'List-Unsubscribe: <' . $unsubscribeUrl . '>',
            'Date: ' . gmdate('D, d M Y H:i:s O'),
            '',
            $htmlText,
        ];

        fwrite($socket, implode("\r\n", $headers) . "\r\n.\r\n");
        $mailStatus = $readResponse($socket);
        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return strpos($mailStatus, '250') === 0 || strpos($mailStatus, '251') === 0;
    }

    if (!function_exists('curl_init')) {
        if ($provider === 'smtp') {
            $headers = [
                'From: ' . $fromName . ' <' . $fromEmail . '>',
                'Reply-To: ' . $fromEmail,
                'X-Mailer: MaVieAuJapon/Newsletter',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'List-Unsubscribe: <' . $unsubscribeUrl . '>',
            ];
            return mail($toEmail, $subject, $htmlText, implode("\r\n", $headers), '-f' . $fromEmail);
        }
        return false;
    }

    if ($provider === 'mailgun') {
        $apiKey = is_string($newsletter['mailgun']['api_key'] ?? null) ? trim((string) $newsletter['mailgun']['api_key']) : '';
        $domain = is_string($newsletter['mailgun']['domain'] ?? null) ? trim((string) $newsletter['mailgun']['domain']) : '';
        if ($apiKey === '' || $domain === '') {
            return false;
        }

        $ch = curl_init('https://api.mailgun.net/v3/' . rawurlencode($domain) . '/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'from' => $fromName . ' <' . $fromEmail . '>',
                'to' => $toEmail,
                'subject' => $subject,
                'text' => $plainText,
                'html' => $htmlText,
            ],
            CURLOPT_USERPWD => 'api:' . $apiKey,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 200 && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);
        return $ok;
    }

    if ($provider === 'brevo') {
        $apiKey = is_string($newsletter['brevo']['api_key'] ?? null) ? trim((string) $newsletter['brevo']['api_key']) : '';
        if ($apiKey === '') {
            return false;
        }

        $payload = [
            'sender' => ['name' => $fromName, 'email' => $fromEmail],
            'to' => [['email' => $toEmail]],
            'subject' => $subject,
            'htmlContent' => $htmlText,
            'textContent' => $plainText,
        ];
        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'api-key: ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 200 && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);
        return $ok;
    }

    if ($provider === 'sendgrid') {
        $apiKey = is_string($newsletter['sendgrid']['api_key'] ?? null) ? trim((string) $newsletter['sendgrid']['api_key']) : '';
        if ($apiKey === '') {
            return false;
        }

        $payload = [
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'personalizations' => [['to' => [['email' => $toEmail]]]],
            'subject' => $subject,
            'content' => [
                ['type' => 'text/plain', 'value' => $plainText],
                ['type' => 'text/html', 'value' => $htmlText],
            ],
        ];
        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $ok = $response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) >= 200 && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 300;
        curl_close($ch);
        return $ok;
    }

    $headers = [
        'From: ' . $fromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'X-Mailer: MaVieAuJapon/Newsletter',
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
    ];
    return mail($toEmail, $subject, $htmlText, implode("\r\n", $headers), '-f' . $fromEmail);
}

function adminNewsletterDispatch(PDO $pdo, array $config, string $title, string $slug, string $language): void
{
    $newsletter = $config['newsletter'] ?? [];
    if (!is_array($newsletter) || (($newsletter['enabled'] ?? false) !== true)) {
        return;
    }

    $query = $pdo->query("SELECT email FROM mvaj_newsletter_subscribers WHERE is_active = 1 ORDER BY created_at ASC");
    $emails = array_map(static fn(array $row): string => (string) $row['email'], $query->fetchAll());
    if ($emails === []) {
        $pdo->prepare("INSERT INTO mvaj_newsletter_sends (article_slug, article_title, language, recipients_count, status, sent_at) VALUES (?, ?, ?, 0, 'skipped', datetime('now'))")->execute([$slug, $title, $language]);
        return;
    }

    $fromEmail = is_string($newsletter['from_email'] ?? null) ? trim((string) $newsletter['from_email']) : 'newsletter@localhost';
    $fromName = is_string($newsletter['sender_name'] ?? null) ? trim((string) $newsletter['sender_name']) : 'Un Belge au Japon';
    $siteUrl = is_string($newsletter['site_url'] ?? null) ? rtrim(trim((string) $newsletter['site_url']), '/') : 'https://example.com';
    $sent = 0;
    foreach ($emails as $email) {
        $emailBundle = adminNewsletterBuildEmail($newsletter, $email, $title, $siteUrl);
        if (adminNewsletterSendWithProvider(
            $newsletter,
            $email,
            $emailBundle['subject'],
            $emailBundle['plain'],
            $emailBundle['html'],
            $emailBundle['from_email'],
            $emailBundle['from_name'],
            $emailBundle['unsubscribe_url']
        )) {
            $sent++;
        }
    }

    $status = $sent > 0 ? 'sent' : 'failed';
    $insert = $pdo->prepare('INSERT INTO mvaj_newsletter_sends (article_slug, article_title, language, recipients_count, status, sent_at) VALUES (?, ?, ?, ?, ?, datetime("now"))');
    $insert->execute([$slug, $title, $language, $sent, $status]);
}

function adminHandle(PDO $pdo, array $config, string $action, string $method): never
{
    $body = $method === 'POST' ? adminRequestBody() : [];

    if ($action === 'session') {
        if ($method !== 'GET') {
            apiFail(405, 'Méthode non autorisée.');
        }
        $user = adminUser($pdo);
        apiReply(200, [
            'authenticated' => $user !== null,
            'user' => $user,
            'roles' => $user['roles'] ?? [],
            'mustChangePassword' => $user['must_change_password'] ?? false,
            'needsBootstrap' => adminUserCount($pdo) === 0,
            'csrf' => (string) $_SESSION['csrf_token'],
        ]);
    }

    if ($action === 'analytics.track') {
        if ($method !== 'POST') {
            apiFail(405, 'Méthode non autorisée.');
        }
        $payload = is_array($body) ? $body : [];
        $path = is_string($payload['path'] ?? null) ? trim((string) $payload['path']) : '';
        if ($path === '') {
            apiFail(400, 'La page tracking est obligatoire.');
        }
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $identity = hash('sha256', ($userAgent === '' ? 'unknown' : $userAgent) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $insert = $pdo->prepare('INSERT INTO mvaj_analytics_events (path, page_title, language, referrer, user_agent_hash, created_at) VALUES (?, ?, ?, ?, ?, datetime("now"))');
        $insert->execute([
            $path,
            is_string($payload['title'] ?? null) ? substr((string) $payload['title'], 0, 255) : '',
            is_string($payload['language'] ?? null) ? substr((string) $payload['language'], 0, 20) : 'fr',
            is_string($payload['referrer'] ?? null) ? substr((string) $payload['referrer'], 0, 255) : '',
            $identity,
        ]);
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'analytics.summary') {
        if ($method !== 'GET') {
            apiFail(405, 'Méthode non autorisée.');
        }
        adminRequireRole($pdo, ['admin']);
        apiReply(200, ['summary' => adminNewsletterSummary($pdo)]);
    }

    if ($action === 'newsletter.subscribe') {
        if ($method !== 'POST') {
            apiFail(405, 'Méthode non autorisée.');
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            apiFail(400, 'L’adresse e-mail est invalide.');
        }
        $source = is_string($body['source'] ?? null) ? trim((string) $body['source']) : 'site';
        $check = $pdo->prepare('SELECT id, is_active FROM mvaj_newsletter_subscribers WHERE email = ? LIMIT 1');
        $check->execute([$email]);
        $record = $check->fetch();
        if ($record) {
            if ((int) $record['is_active'] === 0) {
                $activate = $pdo->prepare('UPDATE mvaj_newsletter_subscribers SET is_active = 1, consent_at = datetime("now"), unsubscribed_at = NULL WHERE id = ?');
                $activate->execute([(int) $record['id']]);
            }
            apiReply(200, ['ok' => true, 'subscribed' => true, 'source' => $source]);
        }
        $insert = $pdo->prepare('INSERT INTO mvaj_newsletter_subscribers (email, source, is_active, consent_at, created_at, unsubscribed_at) VALUES (?, ?, 1, datetime("now"), datetime("now"), NULL)');
        $insert->execute([$email, substr($source, 0, 80)]);
        apiReply(201, ['ok' => true, 'subscribed' => true, 'source' => $source]);
    }

    if ($action === 'newsletter.unsubscribe') {
        if ($method !== 'POST') {
            apiFail(405, 'Méthode non autorisée.');
        }
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            apiFail(400, 'L’adresse e-mail est invalide.');
        }
        $stmt = $pdo->prepare('UPDATE mvaj_newsletter_subscribers SET is_active = 0, unsubscribed_at = datetime("now") WHERE email = ?');
        $stmt->execute([$email]);
        apiReply(200, ['ok' => true, 'unsubscribed' => true]);
    }

    if ($action === 'newsletter.list') {
        if ($method !== 'GET') {
            apiFail(405, 'Méthode non autorisée.');
        }
        adminRequireRole($pdo, ['admin']);
        $rows = $pdo->query('SELECT id, email, source, is_active, consent_at, created_at, unsubscribed_at FROM mvaj_newsletter_subscribers ORDER BY created_at DESC')->fetchAll();
        apiReply(200, ['subscribers' => $rows, 'summary' => adminNewsletterSummary($pdo)]);
    }

    if ($action === 'newsletter.toggle') {
        if ($method !== 'POST') {
            apiFail(405, 'Méthode non autorisée.');
        }
        adminRequireRole($pdo, ['admin']);
        $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            apiFail(400, 'L’abonné sélectionné est invalide.');
        }
        $update = $pdo->prepare('UPDATE mvaj_newsletter_subscribers SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END, unsubscribed_at = CASE WHEN is_active = 1 THEN datetime("now") ELSE NULL END WHERE id = ?');
        $update->execute([$id]);
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'users.list') {
        if ($method !== 'GET') {
            apiFail(405, 'Méthode non autorisée.');
        }
        adminRequireRole($pdo, ['admin']);
        $rows = $pdo->query("SELECT u.id, u.email, u.display_name, u.created_at, GROUP_CONCAT(r.role, ',') AS roles FROM mvaj_admin_users u LEFT JOIN mvaj_admin_user_roles r ON r.user_id = u.id WHERE u.is_active = 1 GROUP BY u.id ORDER BY u.display_name, u.email")->fetchAll();
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['roles'] = $row['roles'] === null || $row['roles'] === '' ? [] : explode(',', (string) $row['roles']);
        }
        unset($row);
        apiReply(200, ['users' => $rows, 'availableRoles' => adminRoles()]);
    }

    if ($action === 'articles.list') {
        if ($method !== 'GET') {
            apiFail(405, 'Méthode non autorisée.');
        }
        adminRequireRole($pdo, ['translator', 'admin']);
        $articles = [];
        foreach (adminGithubTree($config) as $node) {
            $path = $node['path'] ?? '';
            if (!is_string($path) || ($node['type'] ?? '') !== 'blob' || !preg_match('~^content/Blog/(histoire|info|quotidien)/([a-z0-9]+(?:-[a-z0-9]+)*)/index\\.(fr|ja)\\.md$~', $path, $matches)) {
                continue;
            }
            $key = $matches[1] . '/' . $matches[2];
            if (!isset($articles[$key])) {
                $articles[$key] = ['section' => $matches[1], 'slug' => $matches[2], 'languages' => []];
            }
            $articles[$key]['languages'][] = $matches[3];
        }
        apiReply(200, ['articles' => array_values($articles)]);
    }

    if ($action === 'articles.get') {
        if ($method !== 'GET') {
            apiFail(405, 'Méthode non autorisée.');
        }
        adminRequireRole($pdo, ['translator', 'admin']);
        $section = adminArticleSection($_GET['section'] ?? null);
        $slug = adminArticleSlug($_GET['slug'] ?? null);
        $path = adminArticlePath($section, $slug, 'fr');
        $content = adminGithubFileText($config, $path);
        if ($content === null) {
            apiFail(404, 'Article français introuvable.');
        }
        apiReply(200, ['section' => $section, 'slug' => $slug, 'source' => $content]);
    }

    if ($method !== 'POST') {
        apiFail(405, 'Méthode non autorisée.');
    }
    if (!in_array($action, ['analytics.track', 'newsletter.subscribe', 'newsletter.unsubscribe'], true)) {
        adminRequireCsrf();
    }

    if ($action === 'bootstrap') {
        if (adminUserCount($pdo) !== 0) {
            apiFail(409, 'La création initiale du compte administrateur est déjà terminée.');
        }
        $expectedKey = $config['bootstrap_key'] ?? '';
        $providedKey = $body['bootstrapKey'] ?? '';
        if (!is_string($expectedKey) || strlen($expectedKey) < 32 || !is_string($providedKey) || !hash_equals($expectedKey, $providedKey)) {
            apiFail(403, 'La clé d’initialisation est incorrecte.');
        }
        $email = adminEmail((string) ($body['email'] ?? ''));
        $name = adminDisplayName((string) ($body['displayName'] ?? ''));
        $password = $body['password'] ?? '';
        if (!is_string($password)) {
            apiFail(400, 'Le mot de passe est invalide.');
        }
        adminEnsurePassword($password);

        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO mvaj_admin_users (email, display_name, password_hash) VALUES (?, ?, ?)');
            $insert->execute([$email, $name, adminPasswordHash($password)]);
            $userId = (int) $pdo->lastInsertId();
            $roleInsert = $pdo->prepare('INSERT INTO mvaj_admin_user_roles (user_id, role) VALUES (?, ?)');
            $roleInsert->execute([$userId, 'admin']);
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        adminRotateSession();
        $_SESSION['user_id'] = $userId;
        apiReply(201, ['ok' => true]);
    }

    if ($action === 'login') {
        $email = strtolower(trim((string) ($body['email'] ?? '')));
        $password = $body['password'] ?? '';
        if (!is_string($password) || strlen($email) > 254) {
            apiFail(400, 'Adresse e-mail ou mot de passe incorrect.');
        }
        [$ipHash, $emailHash] = adminRateLimitHashes($email, $config);
        adminCheckLoginRate($pdo, $ipHash, $emailHash);
        $query = $pdo->prepare('SELECT id, password_hash, is_active FROM mvaj_admin_users WHERE email = ? LIMIT 1');
        $query->execute([$email]);
        $record = $query->fetch();
        if (!$record || (int) $record['is_active'] !== 1 || !password_verify($password, (string) $record['password_hash'])) {
            adminRecordLoginFailure($pdo, $ipHash, $emailHash);
            apiFail(401, 'Adresse e-mail ou mot de passe incorrect.');
        }
        $clear = $pdo->prepare('DELETE FROM mvaj_admin_login_attempts WHERE ip_hash = ? AND email_hash = ?');
        $clear->execute([$ipHash, $emailHash]);
        $update = $pdo->prepare('UPDATE mvaj_admin_users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([(int) $record['id']]);
        adminRotateSession();
        $_SESSION['user_id'] = (int) $record['id'];
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'logout') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'password.change') {
        $user = adminRequireUser($pdo, true);
        $oldPassword = $body['oldPassword'] ?? '';
        $newPassword = $body['newPassword'] ?? '';
        if (!is_string($oldPassword) || !is_string($newPassword)) {
            apiFail(400, 'Les mots de passe sont invalides.');
        }
        $query = $pdo->prepare('SELECT password_hash FROM mvaj_admin_users WHERE id = ?');
        $query->execute([$user['id']]);
        $record = $query->fetch();
        if (!$record || !password_verify($oldPassword, (string) $record['password_hash'])) {
            apiFail(400, 'Le mot de passe actuel est incorrect.');
        }
        adminEnsurePassword($newPassword);
        $update = $pdo->prepare('UPDATE mvaj_admin_users SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        $update->execute([adminPasswordHash($newPassword), $user['id']]);
        adminRotateSession();
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'users.create') {
        adminRequireRole($pdo, ['admin']);
        $email = adminEmail((string) ($body['email'] ?? ''));
        $name = adminDisplayName((string) ($body['displayName'] ?? ''));
        $roles = adminValidateRoles($body['roles'] ?? []);
        $temporaryPassword = bin2hex(random_bytes(10));
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare('INSERT INTO mvaj_admin_users (email, display_name, password_hash, must_change_password) VALUES (?, ?, ?, 1)');
            $insert->execute([$email, $name, adminPasswordHash($temporaryPassword)]);
            $userId = (int) $pdo->lastInsertId();
            $roleInsert = $pdo->prepare('INSERT INTO mvaj_admin_user_roles (user_id, role) VALUES (?, ?)');
            foreach ($roles as $role) {
                $roleInsert->execute([$userId, $role]);
            }
            $pdo->commit();
        } catch (PDOException $error) {
            $pdo->rollBack();
            if ($error->getCode() === '23000') {
                apiFail(409, 'Un compte existe déjà avec cette adresse e-mail.');
            }
            throw $error;
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiReply(201, ['ok' => true, 'temporaryPassword' => $temporaryPassword]);
    }

    if ($action === 'users.update') {
        $actor = adminRequireRole($pdo, ['admin']);
        $userId = filter_var($body['userId'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            apiFail(400, 'Le compte sélectionné est invalide.');
        }
        $roles = adminValidateRoles($body['roles'] ?? []);
        $query = $pdo->prepare('SELECT is_active FROM mvaj_admin_users WHERE id = ?');
        $query->execute([$userId]);
        $target = $query->fetch();
        if (!$target || (int) $target['is_active'] !== 1) {
            apiFail(404, 'Compte introuvable.');
        }
        $oldRolesQuery = $pdo->prepare('SELECT role FROM mvaj_admin_user_roles WHERE user_id = ?');
        $oldRolesQuery->execute([$userId]);
        $oldRoles = array_column($oldRolesQuery->fetchAll(), 'role');
        if (in_array('admin', $oldRoles, true) && !in_array('admin', $roles, true)) {
            $activeAdmins = (int) $pdo->query("SELECT COUNT(DISTINCT u.id) FROM mvaj_admin_users u INNER JOIN mvaj_admin_user_roles r ON r.user_id = u.id WHERE u.is_active = 1 AND r.role = 'admin'")->fetchColumn();
            if ($activeAdmins <= 1) {
                apiFail(409, 'Il faut conserver au moins un compte administrateur actif.');
            }
        }
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM mvaj_admin_user_roles WHERE user_id = ?');
            $delete->execute([$userId]);
            $insert = $pdo->prepare('INSERT INTO mvaj_admin_user_roles (user_id, role) VALUES (?, ?)');
            foreach ($roles as $role) {
                $insert->execute([$userId, $role]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiReply(200, ['ok' => true, 'updatedBy' => $actor['id']]);
    }

    if ($action === 'users.delete') {
        $actor = adminRequireRole($pdo, ['admin']);
        $userId = filter_var($body['userId'] ?? null, FILTER_VALIDATE_INT);
        if (!$userId || $userId < 1) {
            apiFail(400, 'Le compte sélectionné est invalide.');
        }
        if ($userId === $actor['id']) {
            apiFail(409, 'Tu ne peux pas supprimer le compte avec lequel tu es connecté.');
        }
        $exists = $pdo->prepare('SELECT id FROM mvaj_admin_users WHERE id = ? AND is_active = 1');
        $exists->execute([$userId]);
        if (!$exists->fetchColumn()) {
            apiFail(404, 'Compte introuvable.');
        }
        $rolesQuery = $pdo->prepare('SELECT role FROM mvaj_admin_user_roles WHERE user_id = ?');
        $rolesQuery->execute([$userId]);
        $roles = array_column($rolesQuery->fetchAll(), 'role');
        if (in_array('admin', $roles, true)) {
            $activeAdmins = (int) $pdo->query("SELECT COUNT(DISTINCT u.id) FROM mvaj_admin_users u INNER JOIN mvaj_admin_user_roles r ON r.user_id = u.id WHERE u.is_active = 1 AND r.role = 'admin'")->fetchColumn();
            if ($activeAdmins <= 1) {
                apiFail(409, 'Il faut conserver au moins un compte administrateur actif.');
            }
        }
        $delete = $pdo->prepare('DELETE FROM mvaj_admin_users WHERE id = ?');
        $delete->execute([$userId]);
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'articles.create') {
        adminRequireRole($pdo, ['editor']);
        adminCreateArticle($config, $body);
        adminNewsletterDispatch($pdo, $config, (string) ($body['title'] ?? 'Nouvel article'), (string) ($body['slug'] ?? 'nouvel-article'), 'fr');
        apiReply(201, ['ok' => true]);
    }

    if ($action === 'articles.translate') {
        adminRequireRole($pdo, ['translator']);
        adminTranslateArticle($config, $body);
        adminNewsletterDispatch($pdo, $config, (string) ($body['title'] ?? 'Nouvel article'), (string) ($body['slug'] ?? 'nouvel-article'), 'ja');
        apiReply(200, ['ok' => true]);
    }

    if ($action === 'articles.delete') {
        adminRequireRole($pdo, ['admin']);
        $section = adminArticleSection($body['section'] ?? null);
        $slug = adminArticleSlug($body['slug'] ?? null);
        $deletedFiles = adminDeleteArticle($config, $section, $slug);
        apiReply(200, ['ok' => true, 'deletedFiles' => $deletedFiles]);
    }

    apiFail(404, 'Action inconnue.');
}

try {
    $config = loadAdminConfig();
    adminEnforceHttps(($config['require_https'] ?? true) === true, true);
    adminStartSession();
    $pdo = adminDatabase($config);
    ensureAdminSchema($pdo);
    $action = $_GET['action'] ?? 'session';
    if (!is_string($action) || !preg_match('/^[a-z.]+$/', $action)) {
        apiFail(400, 'Action invalide.');
    }
    adminHandle($pdo, $config, $action, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
} catch (AdminApiException $error) {
    apiReply($error->httpStatus, ['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('MaVieAuJapon admin API failure: ' . get_class($error));
    apiReply(500, ['error' => 'Une erreur interne est survenue. Réessaie plus tard.']);
}
