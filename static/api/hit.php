<?php
declare(strict_types=1);

/** Point d'entrée du compteur de visites (voir /s.js). Répond toujours 204. */

require_once __DIR__ . '/../admin/inc/core.php';
require_once __DIR__ . '/../admin/inc/stats.php';

header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        recordHit(body(2000));
        statsPrune();
    } catch (Throwable $e) {
        error_log('[mvaj-hit] ' . $e->getMessage());
    }
}
http_response_code(204);
