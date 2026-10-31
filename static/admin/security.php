<?php
declare(strict_types=1);

function adminIsHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function adminIsLocalRequest(): bool
{
    $remoteAddress = strtolower((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!in_array($remoteAddress, ['127.0.0.1', '127.0.0.4', '::1'], true)) {
        return false;
    }
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    $host = preg_replace('/:\\d+$/', '', $host) ?? $host;
    return in_array($host, ['localhost', '127.0.0.1', '127.0.0.4', '::1'], true);
}

function adminSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'");
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');
    if (adminIsHttps()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function adminEnforceHttps(bool $enabled, bool $forApi): void
{
    if (!$enabled || adminIsHttps() || adminIsLocalRequest()) {
        return;
    }

    if ($forApi) {
        http_response_code(426);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Une connexion HTTPS est requise.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/admin/');
    header('Location: https://' . $host . $uri, true, 308);
    exit;
}

function adminStartSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', '28800');
    session_name('MVAJADMIN');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => adminIsHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}
