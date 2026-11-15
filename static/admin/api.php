<?php
declare(strict_types=1);

/**
 * API JSON de l'administration. Une action = une entrée de $routes.
 * GET pour lire, POST pour écrire (jeton CSRF obligatoire).
 */

require_once __DIR__ . '/inc/core.php';
require_once __DIR__ . '/inc/articles.php';
require_once __DIR__ . '/inc/stats.php';
require_once __DIR__ . '/inc/newsletter.php';

securityHeaders();

/** @var array<string, array{0:string,1:bool,2:callable}> action => [méthode, auth, gestionnaire] */
$routes = [
    'session' => ['GET', false, static fn(): array => [
        'authenticated' => isLoggedIn(),
        'csrf' => $_SESSION['csrf'],
        'email' => isLoggedIn() ? config()['admin_email'] : null,
    ]],
    'login' => ['POST', false, static function (array $in): array {
        login((string) ($in['email'] ?? ''), (string) ($in['password'] ?? ''));
        return ['ok' => true, 'csrf' => $_SESSION['csrf']];
    }],
    'logout' => ['POST', true, static function (): array {
        logout();
        return ['ok' => true];
    }],

    'stats' => ['GET', true, static fn(): array => statsSummary((int) ($_GET['days'] ?? 30))],

    'articles.list' => ['GET', true, static fn(): array => ['articles' => articlesList()]],
    'articles.get' => ['GET', true, static fn(): array => articleGet(
        articleSegment($_GET['section'] ?? null, 'Rubrique'),
        articleSegment($_GET['slug'] ?? null, 'Slug'),
        articleLang($_GET['lang'] ?? null)
    )],
    'articles.save' => ['POST', true, static function (array $in): array {
        $sha = isset($in['sha']) && is_string($in['sha']) && $in['sha'] !== '' ? $in['sha'] : null;
        $newSha = articleSave(
            articleSegment($in['section'] ?? null, 'Rubrique'),
            articleSegment($in['slug'] ?? null, 'Slug'),
            articleLang($in['lang'] ?? null),
            $in['content'] ?? null,
            $sha
        );
        return ['ok' => true, 'sha' => $newSha];
    }],
    'images.upload' => ['POST', true, static fn(array $in): array => imageUpload($in['name'] ?? '', $in['data'] ?? null)],
    'articles.create' => ['POST', true, static fn(array $in): array => articleCreate($in)],
    'articles.delete' => ['POST', true, static fn(array $in): array => [
        'ok' => true,
        'deleted' => articleDelete(articleSegment($in['section'] ?? null, 'Rubrique'), articleSegment($in['slug'] ?? null, 'Slug')),
    ]],

    'newsletter.overview' => ['GET', true, static fn(): array => newsletterOverview()],
    'newsletter.articles' => ['GET', true, static fn(): array => ['urls' => liveArticleUrls()]],
    'newsletter.remove' => ['POST', true, static function (array $in): array {
        db()->prepare('DELETE FROM subscribers WHERE id = ?')->execute([(int) ($in['id'] ?? 0)]);
        return ['ok' => true];
    }],
    'newsletter.add' => ['POST', true, static function (array $in): array {
        $email = normalizeEmail($in['email'] ?? '');
        db()->prepare("INSERT INTO subscribers (email, lang, status, token, created_at, confirmed_at) VALUES (?, ?, 'active', ?, ?, ?) ON CONFLICT(email) DO UPDATE SET status = 'active'")
            ->execute([$email, ($in['lang'] ?? 'fr') === 'ja' ? 'ja' : 'fr', bin2hex(random_bytes(16)), time(), time()]);
        return ['ok' => true];
    }],
    'newsletter.test' => ['POST', true, static function (array $in): array {
        campaignTest(sameSiteUrl((string) ($in['url'] ?? '')), (string) ($in['intro'] ?? ''), (string) config()['admin_email']);
        return ['ok' => true];
    }],
    'newsletter.campaign' => ['POST', true, static fn(array $in): array => campaignCreate(sameSiteUrl((string) ($in['url'] ?? '')), (string) ($in['intro'] ?? ''))],
    'newsletter.step' => ['POST', true, static fn(array $in): array => campaignStep((int) ($in['id'] ?? 0))],
];

try {
    startSession();
    $action = (string) ($_GET['action'] ?? '');
    if (!isset($routes[$action])) {
        fail(404, 'Action inconnue.');
    }
    [$method, $needsAuth, $handler] = $routes[$action];
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        fail(405, 'Méthode non autorisée.');
    }
    if ($needsAuth) {
        requireAdmin($method === 'POST');
    } elseif ($method === 'POST') {
        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin === '' || strcasecmp((string) parse_url($origin, PHP_URL_HOST), explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]) !== 0) {
            fail(403, 'Origine refusée.');
        }
    }
    reply($handler($method === 'POST' ? body(6_000_000) : []));
} catch (ApiError $e) {
    reply(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[mvaj-admin] ' . $e);
    reply(['error' => 'Erreur interne.'], 500);
}
