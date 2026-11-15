<?php
declare(strict_types=1);

/**
 * Statistiques internes, sans cookie ni donnée personnelle conservée.
 * Le « visiteur » est un haché (IP + navigateur + jour) qui change chaque jour
 * et ne peut pas être relié à une personne : aucun consentement n'est requis.
 */

function statsTimezone(): DateTimeZone
{
    return new DateTimeZone(is_string(config()['timezone'] ?? null) ? config()['timezone'] : 'Europe/Brussels');
}

function isBot(string $userAgent): bool
{
    return $userAgent === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|java\/|go-http|httpclient|facebookexternalhit|embedly|scrapy/i', $userAgent) === 1;
}

function deviceOf(string $userAgent): string
{
    if (preg_match('/ipad|tablet|kindle|silk/i', $userAgent)) {
        return 'tablet';
    }
    return preg_match('/mobi|iphone|android/i', $userAgent) ? 'mobile' : 'desktop';
}

/** Enregistre une page vue après validation. Ignore silencieusement le bruit. */
function recordHit(array $in): void
{
    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (isBot($userAgent)) {
        return;
    }

    $siteHost = (string) parse_url(siteUrl(), PHP_URL_HOST);
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = (string) parse_url($origin, PHP_URL_HOST);
        if ($siteHost !== '' && strcasecmp($originHost, $siteHost) !== 0 && !in_array($originHost, ['localhost', '127.0.0.1', '127.0.0.4'], true)) {
            return;
        }
    }

    $path = (string) ($in['p'] ?? '');
    $path = (string) (parse_url($path, PHP_URL_PATH) ?? '');
    if ($path === '' || $path[0] !== '/' || strlen($path) > 300 || str_starts_with($path, '/admin') || str_starts_with($path, '/api')) {
        return;
    }

    $referrerHost = strtolower((string) parse_url((string) ($in['r'] ?? ''), PHP_URL_HOST));
    $referrerHost = preg_replace('/^www\./', '', $referrerHost) ?? '';
    if ($referrerHost === preg_replace('/^www\./', '', strtolower($siteHost)) || strlen($referrerHost) > 100) {
        $referrerHost = '';
    }

    $lang = strtolower(substr(preg_replace('/[^a-zA-Z]/', '', (string) ($in['l'] ?? '')) ?? '', 0, 2));
    $now = new DateTimeImmutable('now', statsTimezone());
    $day = $now->format('Y-m-d');
    $visitor = secretHash(clientIp() . '|' . $userAgent, 'hit' . $day);

    db()->prepare('INSERT INTO hits (ts, day, path, lang, ref, visitor, device) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$now->getTimestamp(), $day, $path, $lang, $referrerHost, $visitor, deviceOf($userAgent)]);
}

/** @return array<string,mixed> */
function statsSummary(int $days): array
{
    $days = max(1, min(365, $days));
    $pdo = db();
    $today = new DateTimeImmutable('today', statsTimezone());
    $from = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
    $prevFrom = $today->modify('-' . (2 * $days - 1) . ' days')->format('Y-m-d');

    $series = [];
    $query = $pdo->prepare('SELECT day, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM hits WHERE day >= ? GROUP BY day');
    $query->execute([$prevFrom]);
    $byDay = [];
    foreach ($query->fetchAll() as $row) {
        $byDay[$row['day']] = ['views' => (int) $row['views'], 'visitors' => (int) $row['visitors']];
    }

    $totals = ['views' => 0, 'visitors' => 0];
    $previous = ['views' => 0, 'visitors' => 0];
    for ($i = 2 * $days - 1; $i >= 0; $i--) {
        $day = $today->modify("-$i days")->format('Y-m-d');
        $entry = $byDay[$day] ?? ['views' => 0, 'visitors' => 0];
        if ($i >= $days) {
            $previous['views'] += $entry['views'];
            $previous['visitors'] += $entry['visitors'];
            continue;
        }
        $series[] = ['day' => $day] + $entry;
        $totals['views'] += $entry['views'];
        $totals['visitors'] += $entry['visitors'];
    }

    $group = static function (string $sql) use ($pdo, $from): array {
        $statement = $pdo->prepare($sql);
        $statement->execute([$from]);
        return array_map(static fn(array $r): array => ['label' => (string) $r['label'], 'views' => (int) $r['views'], 'visitors' => (int) $r['visitors']], $statement->fetchAll());
    };
    $columns = 'COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors';

    return [
        'days' => $days,
        'totals' => $totals,
        'previous' => $previous,
        'series' => $series,
        'pages' => $group("SELECT path AS label, $columns FROM hits WHERE day >= ? GROUP BY path ORDER BY views DESC LIMIT 15"),
        'referrers' => $group("SELECT CASE WHEN ref = '' THEN '(direct)' ELSE ref END AS label, $columns FROM hits WHERE day >= ? GROUP BY label ORDER BY views DESC LIMIT 10"),
        'languages' => $group("SELECT CASE WHEN lang = '' THEN '?' ELSE lang END AS label, $columns FROM hits WHERE day >= ? GROUP BY label ORDER BY views DESC LIMIT 6"),
        'devices' => $group("SELECT device AS label, $columns FROM hits WHERE day >= ? GROUP BY device ORDER BY views DESC"),
    ];
}

/** Supprime les visites de plus de 26 mois (appelé de façon opportuniste). */
function statsPrune(): void
{
    if (random_int(1, 200) !== 1) {
        return;
    }
    $limit = (new DateTimeImmutable('today', statsTimezone()))->modify('-26 months')->format('Y-m-d');
    db()->prepare('DELETE FROM hits WHERE day < ?')->execute([$limit]);
}
