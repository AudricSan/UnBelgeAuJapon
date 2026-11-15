<?php
declare(strict_types=1);

require_once __DIR__ . '/inc/core.php';
securityHeaders();
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <title>Administration — Un Belge au Japon</title>
  <link rel="icon" href="/favicon.svg">
  <link rel="stylesheet" href="/admin/admin.css">
  <script src="/admin/app.js" defer></script>
</head>
<body>
  <div id="app"><p class="loading">Chargement…</p></div>
  <noscript><p class="loading">JavaScript est nécessaire pour utiliser l’administration.</p></noscript>
</body>
</html>
