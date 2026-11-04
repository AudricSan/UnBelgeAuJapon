<?php
declare(strict_types=1);

require_once __DIR__ . '/mail.php';

/**
 * Newsletter : inscription avec confirmation (double opt-in), désinscription
 * par jeton, campagnes envoyées par petits lots (compatible hébergement mutualisé).
 */

const NEWSLETTER_BATCH = 10;

const MAIL_TEXT = [
    'fr' => [
        'confirm_subject' => 'Confirme ton inscription à la newsletter',
        'confirm_body' => "Bonjour,\n\nClique sur ce lien pour confirmer ton inscription à la newsletter d'« Un Belge au Japon » :\n%s\n\nSi tu n'es pas à l'origine de cette demande, ignore simplement ce message.",
        'new_article' => 'Nouvel article sur Un Belge au Japon',
        'read' => 'Lire l’article',
        'unsubscribe' => 'Se désinscrire',
        'why' => 'Tu reçois ce message car tu t’es abonné·e à la newsletter.',
    ],
    'ja' => [
        'confirm_subject' => 'ニュースレター登録の確認',
        'confirm_body' => "こんにちは。\n\n「Un Belge au Japon」のニュースレター登録を確認するには、次のリンクを開いてください：\n%s\n\n心当たりがない場合は、このメールを無視してください。",
        'new_article' => 'Un Belge au Japon の新着記事',
        'read' => '記事を読む',
        'unsubscribe' => '配信停止',
        'why' => 'ニュースレターに登録されているため、このメールをお送りしています。',
    ],
];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function mailText(string $lang): array
{
    return MAIL_TEXT[$lang] ?? MAIL_TEXT['fr'];
}

function normalizeEmail(mixed $email): string
{
    $email = strtolower(trim((string) $email));
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail(400, 'Adresse e-mail invalide.');
    }
    return $email;
}

function unsubscribeUrl(string $token): string
{
    return siteUrl() . '/api/newsletter.php?a=unsubscribe&t=' . $token;
}

/** Crée ou relance une inscription en attente et envoie le mail de confirmation. */
function newsletterSubscribe(string $email, string $lang): void
{
    $pdo = db();
    $lang = $lang === 'ja' ? 'ja' : 'fr';
    throttle('subscribe', secretHash(clientIp(), 'sub'), 5, 3600);
    throttleRecord('subscribe', secretHash(clientIp(), 'sub'));

    $row = $pdo->prepare('SELECT id, status, token, mailed_at FROM subscribers WHERE email = ?');
    $row->execute([$email]);
    $subscriber = $row->fetch();

    if ($subscriber && $subscriber['status'] === 'active') {
        return; // Réponse identique côté public : on ne révèle pas qui est inscrit.
    }
    if ($subscriber && $subscriber['mailed_at'] !== null && time() - (int) $subscriber['mailed_at'] < 600) {
        return;
    }

    $token = $subscriber ? (string) $subscriber['token'] : bin2hex(random_bytes(16));
    if ($subscriber) {
        $pdo->prepare("UPDATE subscribers SET status = 'pending', lang = ?, mailed_at = ? WHERE id = ?")->execute([$lang, time(), $subscriber['id']]);
    } else {
        $pdo->prepare("INSERT INTO subscribers (email, lang, status, token, created_at, mailed_at) VALUES (?, ?, 'pending', ?, ?, ?)")->execute([$email, $lang, $token, time(), time()]);
    }

    $text = mailText($lang);
    $link = siteUrl() . '/api/newsletter.php?a=confirm&t=' . $token;
    $plain = sprintf($text['confirm_body'], $link);
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937">'
        . '<p>' . nl2br(h(explode('%s', $text['confirm_body'])[0])) . '</p>'
        . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>'
        . '<p>' . nl2br(h(explode('%s', $text['confirm_body'])[1] ?? '')) . '</p></div>';

    [$fromEmail, $fromName] = mailSender();
    $mailer = smtp();
    try {
        $mailer->send($fromEmail, $fromName, $email, $text['confirm_subject'], $plain, $html);
    } catch (RuntimeException) {
        fail(502, 'Envoi impossible pour le moment. Réessaie plus tard.');
    } finally {
        $mailer->close();
    }
}

/** @return string|null langue de l'abonné si le jeton est valide */
function newsletterConfirm(string $token): ?string
{
    $pdo = db();
    $row = $pdo->prepare('SELECT id, lang FROM subscribers WHERE token = ?');
    $row->execute([$token]);
    $subscriber = $row->fetch();
    if (!$subscriber) {
        return null;
    }
    $pdo->prepare("UPDATE subscribers SET status = 'active', confirmed_at = ? WHERE id = ?")->execute([time(), $subscriber['id']]);
    return (string) $subscriber['lang'];
}

function newsletterUnsubscribe(string $token): bool
{
    $update = db()->prepare("UPDATE subscribers SET status = 'unsubscribed' WHERE token = ?");
    $update->execute([$token]);
    return $update->rowCount() > 0;
}

/* ───────── Campagnes (admin) ───────── */

function httpGet(string $url): string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'MaVieAuJapon-Admin']);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    if (!is_string($body) || $status !== 200) {
        fail(404, 'Page introuvable en ligne (le déploiement est-il terminé ?).');
    }
    return $body;
}

function sameSiteUrl(string $url): string
{
    $site = siteUrl();
    if ($site === '' || !str_starts_with($url, $site . '/') || str_contains($url, ' ')) {
        fail(400, 'L’adresse doit appartenir au site.');
    }
    return $url;
}

/** Pages d'articles publiées, d'après les sitemaps du site. @return list<string> */
function liveArticleUrls(): array
{
    $urls = [];
    foreach (['/fr/sitemap.xml', '/ja/sitemap.xml'] as $path) {
        try {
            preg_match_all('#<loc>([^<]+)</loc>#', httpGet(siteUrl() . $path), $matches);
        } catch (ApiError) {
            continue;
        }
        foreach ($matches[1] as $loc) {
            if (preg_match('#^' . preg_quote(siteUrl(), '#') . '(/ja)?/blog/[^/]+/[^/]+/$#i', $loc)) {
                $urls[] = $loc;
            }
        }
    }
    sort($urls);
    return array_values(array_unique($urls));
}

/** @return array{title:string,description:string,lang:string} */
function pageMeta(string $url): array
{
    $html = httpGet(sameSiteUrl($url));
    $find = static function (string $pattern) use ($html): string {
        return preg_match($pattern, $html, $m) ? html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    };
    $title = $find('#<meta[^>]+property="og:title"[^>]+content="([^"]*)"#i') ?: $find('#<title>([^<]*)</title>#i');
    $title = trim(explode(' | ', $title)[0]);
    return [
        'title' => $title,
        'description' => $find('#<meta[^>]+name="description"[^>]+content="([^"]*)"#i'),
        'lang' => $find('#<html[^>]+lang="?([a-z]{2})#i') ?: 'fr',
    ];
}

function campaignCreate(string $url, string $intro): array
{
    $meta = pageMeta($url);
    if ($meta['title'] === '') {
        fail(400, 'Titre de la page introuvable.');
    }
    $text = mailText($meta['lang']);
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO campaigns (subject, title, url, intro, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$text['new_article'] . ' : ' . $meta['title'], $meta['title'], $url, trim($intro) !== '' ? trim($intro) : $meta['description'], time()]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO deliveries (campaign_id, subscriber_id) SELECT ?, id FROM subscribers WHERE status = 'active' AND lang = ?")
        ->execute([$id, $meta['lang']]);
    $pdo->commit();
    return campaignProgress($id);
}

function campaignProgress(int $id): array
{
    $pdo = db();
    $campaign = $pdo->prepare('SELECT id, subject, title, url, created_at FROM campaigns WHERE id = ?');
    $campaign->execute([$id]);
    $row = $campaign->fetch();
    if (!$row) {
        fail(404, 'Campagne introuvable.');
    }
    $counts = $pdo->prepare('SELECT status, COUNT(*) AS n FROM deliveries WHERE campaign_id = ? GROUP BY status');
    $counts->execute([$id]);
    $by = ['pending' => 0, 'sent' => 0, 'failed' => 0];
    foreach ($counts->fetchAll() as $c) {
        $by[$c['status']] = (int) $c['n'];
    }
    return $row + $by + ['total' => array_sum($by)];
}

function campaignEmail(array $campaign, string $lang, string $unsubscribe): array
{
    $text = mailText($lang);
    $intro = (string) $campaign['intro'];
    $plain = $campaign['title'] . "\n\n" . ($intro !== '' ? $intro . "\n\n" : '') . $text['read'] . ' : ' . $campaign['url'] . "\n\n--\n" . $text['why'] . "\n" . $text['unsubscribe'] . ' : ' . $unsubscribe . "\n";
    $html = '<div style="font-family:Arial,sans-serif;line-height:1.6;color:#1f2937;max-width:560px">'
        . '<h2 style="margin:0 0 12px">' . h((string) $campaign['title']) . '</h2>'
        . ($intro !== '' ? '<p>' . nl2br(h($intro)) . '</p>' : '')
        . '<p><a href="' . h((string) $campaign['url']) . '" style="display:inline-block;background:#d82c2c;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">' . h($text['read']) . '</a></p>'
        . '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0"><p style="font-size:12px;color:#6b7280">' . h($text['why']) . ' <a href="' . h($unsubscribe) . '">' . h($text['unsubscribe']) . '</a></p></div>';
    return [$plain, $html];
}

/** Envoie le prochain lot ; renvoie la progression. Appelé en boucle par l'interface. */
function campaignStep(int $id): array
{
    $pdo = db();
    $campaignQuery = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
    $campaignQuery->execute([$id]);
    $campaign = $campaignQuery->fetch();
    if (!$campaign) {
        fail(404, 'Campagne introuvable.');
    }

    $batch = $pdo->prepare("SELECT s.id, s.email, s.lang, s.token FROM deliveries d JOIN subscribers s ON s.id = d.subscriber_id WHERE d.campaign_id = ? AND d.status = 'pending' LIMIT " . NEWSLETTER_BATCH);
    $batch->execute([$id]);
    $recipients = $batch->fetchAll();

    [$fromEmail, $fromName] = mailSender();
    $mailer = smtp();
    $mark = $pdo->prepare('UPDATE deliveries SET status = ? WHERE campaign_id = ? AND subscriber_id = ?');
    $lastError = '';
    try {
        foreach ($recipients as $recipient) {
            $link = unsubscribeUrl($recipient['token']);
            [$plain, $html] = campaignEmail($campaign, $recipient['lang'], $link);
            try {
                $mailer->send($fromEmail, $fromName, $recipient['email'], $campaign['subject'], $plain, $html, [
                    'List-Unsubscribe' => '<' . $link . '>',
                    'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
                ]);
                $mark->execute(['sent', $id, $recipient['id']]);
            } catch (RuntimeException $e) {
                $lastError = $e->getMessage();
                $mark->execute(['failed', $id, $recipient['id']]);
            }
        }
    } finally {
        $mailer->close();
    }
    return campaignProgress($id) + ['error' => $lastError];
}

function campaignTest(string $url, string $intro, string $to): void
{
    $meta = pageMeta($url);
    $campaign = ['subject' => '[TEST] ' . $meta['title'], 'title' => $meta['title'], 'url' => $url, 'intro' => trim($intro) !== '' ? trim($intro) : $meta['description']];
    [$plain, $html] = campaignEmail($campaign, $meta['lang'], siteUrl() . '/api/newsletter.php?a=unsubscribe&t=test');
    [$fromEmail, $fromName] = mailSender();
    $mailer = smtp();
    try {
        $mailer->send($fromEmail, $fromName, $to, $campaign['subject'], $plain, $html);
    } catch (RuntimeException $e) {
        fail(502, $e->getMessage());
    } finally {
        $mailer->close();
    }
}

function newsletterOverview(): array
{
    $pdo = db();
    $campaigns = array_map(
        static fn(array $c): array => campaignProgress((int) $c['id']),
        $pdo->query('SELECT id FROM campaigns ORDER BY id DESC LIMIT 15')->fetchAll()
    );
    return [
        'counts' => [
            'active' => (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'active'")->fetchColumn(),
            'pending' => (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'pending'")->fetchColumn(),
            'unsubscribed' => (int) $pdo->query("SELECT COUNT(*) FROM subscribers WHERE status = 'unsubscribed'")->fetchColumn(),
        ],
        'subscribers' => $pdo->query('SELECT id, email, lang, status, created_at FROM subscribers ORDER BY created_at DESC LIMIT 500')->fetchAll(),
        'campaigns' => $campaigns,
    ];
}
