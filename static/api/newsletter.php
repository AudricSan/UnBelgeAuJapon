<?php
declare(strict_types=1);

/**
 * Newsletter côté public :
 *   POST ?a=subscribe    {email, lang, website}   → JSON
 *   GET  ?a=confirm&t=…                           → page HTML
 *   GET  ?a=unsubscribe&t=… (page) / POST (lien « un clic » des clients mail)
 */

require_once __DIR__ . '/../admin/inc/core.php';
require_once __DIR__ . '/../admin/inc/newsletter.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

function publicPage(string $title, string $message, string $form = ''): never
{
    header('Content-Type: text/html; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>' . h($title) . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;margin:0;min-height:100vh;display:grid;place-items:center;background:#faf7f2;color:#1f2937}'
        . 'main{max-width:30rem;margin:1rem;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:2rem}'
        . 'h1{margin-top:0;font-size:1.5rem}button{font:inherit;background:#d82c2c;color:#fff;border:0;border-radius:8px;padding:.7rem 1.2rem;cursor:pointer}a{color:#d82c2c}</style></head>'
        . '<body><main><h1>' . h($title) . '</h1><p>' . h($message) . '</p>' . $form . '<p><a href="/">← Un Belge au Japon</a></p></main></body></html>';
    exit;
}

try {
    $action = (string) ($_GET['a'] ?? '');
    $token = (string) ($_GET['t'] ?? '');
    $isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $validToken = preg_match('/^[a-f0-9]{32}$/', $token) === 1;

    if ($action === 'subscribe' && $isPost) {
        $in = body(2000);
        if (!empty($in['website'])) { // champ piège pour les robots
            reply(['ok' => true]);
        }
        newsletterSubscribe(normalizeEmail($in['email'] ?? ''), (string) ($in['lang'] ?? 'fr'));
        reply(['ok' => true]);
    }

    if ($action === 'confirm' && $validToken) {
        $lang = newsletterConfirm($token);
        $lang === null
            ? publicPage('Lien invalide', 'Ce lien de confirmation n’est pas ou plus valide.')
            : publicPage($lang === 'ja' ? '登録が完了しました' : 'Inscription confirmée', $lang === 'ja' ? 'ニュースレターにご登録ありがとうございます。' : 'Merci ! Tu recevras un e-mail à chaque nouvel article.');
    }

    if ($action === 'unsubscribe' && $validToken) {
        if ($isPost) {
            newsletterUnsubscribe($token);
            publicPage('Désinscription effectuée', 'Tu ne recevras plus la newsletter.');
        }
        publicPage('Se désinscrire', 'Confirme pour ne plus recevoir la newsletter.', '<form method="post" action="?a=unsubscribe&amp;t=' . h($token) . '"><button type="submit">Me désinscrire</button></form>');
    }

    publicPage('Lien invalide', 'Cette adresse n’est pas valide.');
} catch (ApiError $e) {
    if (($_GET['a'] ?? '') === 'subscribe') {
        reply(['error' => $e->getMessage()], $e->status);
    }
    publicPage('Erreur', $e->getMessage());
} catch (Throwable $e) {
    error_log('[mvaj-newsletter] ' . $e);
    if (($_GET['a'] ?? '') === 'subscribe') {
        reply(['error' => 'Erreur interne.'], 500);
    }
    publicPage('Erreur', 'Une erreur est survenue.');
}
