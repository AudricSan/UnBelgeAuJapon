<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
adminSecurityHeaders();
adminEnforceHttps(true, false);

$stylesheetFiles = glob(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'css' . DIRECTORY_SEPARATOR . 'stylesheet.*.css') ?: [];
usort($stylesheetFiles, static fn(string $left, string $right): int => filemtime($right) <=> filemtime($left));
$themeStylesheet = $stylesheetFiles === [] ? '' : '/assets/css/' . rawurlencode(basename($stylesheetFiles[0]));
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="theme-color" content="#ffffff">
  <title>Administration — Un Belge au Japon</title>
  <?php if ($themeStylesheet !== ''): ?>
  <link rel="stylesheet" href="<?= htmlspecialchars($themeStylesheet, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
  <?php endif; ?>
  <link rel="stylesheet" href="/admin/admin.css">
  <script src="/admin/admin.js" defer></script>
</head>
<body>
  <main class="admin-shell">
    <header class="admin-header">
      <div>
        <a class="site-brand" href="/">Un Belge au Japon</a>
        <p class="eyebrow">ESPACE D’ÉDITION</p>
        <h1>Administration du blog</h1>
      </div>
      <a class="site-link" href="/">Voir le site <span aria-hidden="true">↗</span></a>
    </header>

    <p id="status-message" class="status-message" role="status" aria-live="polite"></p>

    <section id="bootstrap-panel" class="auth-card" hidden>
      <p class="eyebrow">PREMIÈRE CONFIGURATION</p>
      <h2>Créer le compte administrateur</h2>
      <p class="muted">Cette étape ne fonctionne qu’une seule fois. La clé d’initialisation se trouve dans le fichier de configuration privé du serveur.</p>
      <form id="bootstrap-form" class="form-grid">
        <label class="field field-wide"><span>Clé d’initialisation</span><input name="bootstrapKey" type="password" autocomplete="off" required></label>
        <label class="field"><span>Nom affiché</span><input name="displayName" type="text" maxlength="100" autocomplete="name" required></label>
        <label class="field"><span>Adresse e-mail</span><input name="email" type="email" maxlength="254" autocomplete="email" required></label>
        <label class="field"><span>Mot de passe</span><input name="password" type="password" minlength="14" maxlength="200" autocomplete="new-password" required><small>14 caractères minimum.</small></label>
        <label class="field"><span>Confirmer le mot de passe</span><input name="confirmPassword" type="password" minlength="14" maxlength="200" autocomplete="new-password" required></label>
        <div class="field-wide form-actions"><button class="button button-primary" type="submit">Créer le compte administrateur</button></div>
      </form>
    </section>

    <section id="login-panel" class="auth-card" hidden>
      <p class="eyebrow">CONNEXION SÉCURISÉE</p>
      <h2>Se connecter</h2>
      <form id="login-form" class="form-grid">
        <label class="field field-wide"><span>Adresse e-mail</span><input name="email" type="email" maxlength="254" autocomplete="username" required></label>
        <label class="field field-wide"><span>Mot de passe</span><input name="password" type="password" autocomplete="current-password" required></label>
        <div class="field-wide form-actions"><button class="button button-primary" type="submit">Connexion</button></div>
      </form>
    </section>

    <section id="password-panel" class="auth-card" hidden>
      <p class="eyebrow">SÉCURITÉ DU COMPTE</p>
      <h2>Choisir un mot de passe personnel</h2>
      <p class="muted">Le mot de passe temporaire doit être remplacé avant d’utiliser l’espace d’édition.</p>
      <form id="password-form" class="form-grid">
        <label class="field field-wide"><span>Mot de passe temporaire ou actuel</span><input name="oldPassword" type="password" autocomplete="current-password" required></label>
        <label class="field"><span>Nouveau mot de passe</span><input name="newPassword" type="password" minlength="14" maxlength="200" autocomplete="new-password" required></label>
        <label class="field"><span>Confirmer le nouveau mot de passe</span><input name="confirmPassword" type="password" minlength="14" maxlength="200" autocomplete="new-password" required></label>
        <div class="field-wide form-actions"><button class="button button-primary" type="submit">Enregistrer le mot de passe</button><button id="password-logout" class="button button-secondary" type="button">Se déconnecter</button></div>
      </form>
    </section>

    <section id="app-panel" hidden>
      <header class="account-bar">
        <div><strong id="account-name"></strong><span id="account-roles" class="role-list"></span></div>
        <button id="logout-button" class="button button-secondary" type="button">Se déconnecter</button>
      </header>

      <nav id="admin-tabs" class="admin-tabs" aria-label="Outils d’administration">
        <button class="tab-button" data-tab="create-view" type="button">Créer un article</button>
        <button class="tab-button" data-tab="translate-view" type="button">Traduire un article</button>
        <button class="tab-button" data-tab="newsletter-view" type="button">Analytics & newsletter</button>
        <button class="tab-button" data-tab="users-view" type="button">Utilisateurs et articles</button>
      </nav>

      <section id="create-view" class="workspace-card" hidden>
        <p class="eyebrow">NOUVEL ARTICLE FRANÇAIS</p>
        <h2>Créer un article</h2>
        <form id="article-form" class="form-grid">
          <label class="field"><span>Rubrique</span><select name="section" required><option value="info">Conseils et démarches</option><option value="quotidien">Vie quotidienne</option><option value="histoire">Récits personnels</option></select></label>
          <label class="field"><span>Date de publication</span><input name="date" type="date" required></label>
          <label class="field field-wide"><span>Titre</span><input name="title" type="text" maxlength="500" required></label>
          <label class="field"><span>Slug</span><input name="slug" type="text" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" maxlength="100" required><small>Minuscules, chiffres et tirets.</small></label>
          <label class="field"><span>Mots-clés SEO</span><input name="keywords" type="text" placeholder="Japon, expatriation"></label>
          <label class="field field-wide"><span>Résumé</span><textarea name="description" rows="2" maxlength="2000" required></textarea></label>
          <label class="field"><span>Catégories</span><input name="categories" type="text" placeholder="Vie quotidienne, Culture japonaise"></label>
          <label class="field"><span>Étiquettes</span><input name="tags" type="text" placeholder="matsuri, quartier"></label>
          <label class="check-field field-wide"><input name="draft" type="checkbox"><span>Garder cet article en brouillon</span></label>
          <label class="field field-wide"><span>Contenu en Markdown</span><textarea name="body" rows="18" maxlength="500000" required></textarea></label>
          <div class="field-wide form-actions"><button class="button button-primary" type="submit">Créer dans le dépôt</button></div>
        </form>
      </section>

      <section id="translate-view" class="workspace-card" hidden>
        <p class="eyebrow">TRADUCTION JAPONAISE</p>
        <h2>Traduire un article</h2>
        <form id="translation-form" class="form-grid">
          <label class="field field-wide"><span>Article français à traduire</span><select name="article" required><option value="">Chargement des articles…</option></select></label>
          <div class="field field-wide"><span>Article source</span><pre id="source-preview" class="source-preview">Sélectionne un article pour afficher le texte français.</pre></div>
          <label class="field field-wide"><span>Titre japonais</span><input name="title" type="text" maxlength="500" required></label>
          <label class="field"><span>Date de publication</span><input name="date" type="date" required></label>
          <label class="field"><span>Mots-clés</span><input name="keywords" type="text"></label>
          <label class="field field-wide"><span>Résumé japonais</span><textarea name="description" rows="2" maxlength="2000" required></textarea></label>
          <label class="field"><span>Catégories</span><input name="categories" type="text"></label>
          <label class="field"><span>Étiquettes</span><input name="tags" type="text"></label>
          <label class="field field-wide"><span>Traduction en Markdown</span><textarea name="body" rows="18" maxlength="500000" required></textarea></label>
          <div class="field-wide form-actions"><button class="button button-primary" type="submit">Enregistrer la traduction</button></div>
        </form>
      </section>

      <section id="newsletter-view" class="workspace-card" hidden>
        <p class="eyebrow">ANALYTICS & NEWSLETTER</p>
        <h2>Statistiques et abonnés</h2>
        <div class="stats-grid">
          <article class="stat-card"><span>Événements</span><strong id="stats-total-events">0</strong></article>
          <article class="stat-card"><span>7 derniers jours</span><strong id="stats-week-events">0</strong></article>
          <article class="stat-card"><span>Abonnés</span><strong id="stats-subscribers">0</strong></article>
        </div>

        <div class="stats-columns">
          <section>
            <h3>Pages les plus consultées</h3>
            <ul id="top-pages-list" class="stats-list"></ul>
          </section>
          <section>
            <h3>Abonnés</h3>
            <form id="newsletter-subscribe-form" class="form-grid compact-form">
              <label class="field field-wide"><span>Adresse e-mail</span><input name="email" type="email" maxlength="254" required></label>
              <div class="field-wide form-actions"><button class="button button-primary" type="submit">Ajouter un abonné</button></div>
            </form>
            <div id="newsletter-subscribers" class="users-list"></div>
          </section>
        </div>
      </section>

      <section id="users-view" class="workspace-card" hidden>
        <div id="user-management">
          <p class="eyebrow">COMPTES ET RÔLES</p>
          <h2>Gérer les utilisateurs</h2>
          <p class="muted">Les rôles se cumulent. Le mot de passe temporaire s’affiche une seule fois après la création du compte.</p>
          <form id="user-form" class="form-grid">
            <label class="field"><span>Nom affiché</span><input name="displayName" type="text" maxlength="100" required></label>
            <label class="field"><span>Adresse e-mail</span><input name="email" type="email" maxlength="254" required></label>
            <fieldset class="role-picker field-wide"><legend>Rôles à attribuer</legend>
              <label><input type="checkbox" name="roles" value="editor"><span>Éditeur — créer des articles</span></label>
              <label><input type="checkbox" name="roles" value="translator"><span>Traducteur — créer ou modifier les traductions japonaises</span></label>
              <label><input type="checkbox" name="roles" value="admin"><span>Admin — gérer les utilisateurs et supprimer des articles</span></label>
            </fieldset>
            <div class="field-wide form-actions"><button class="button button-primary" type="submit">Ajouter l’utilisateur</button></div>
          </form>
          <div id="temporary-password" class="temporary-password" hidden></div>
          <div id="users-list" class="users-list"></div>
        </div>
        <div id="article-management" class="article-management" hidden>
          <p class="eyebrow">SUPPRESSION D’ARTICLE</p>
          <h2>Articles du dépôt</h2>
          <p class="muted">La suppression retire le dossier de l’article et toutes ses traductions du dépôt GitHub.</p>
          <div id="delete-articles-list" class="users-list"></div>
        </div>
      </section>
    </section>
    <footer class="admin-footer"><span>Un Belge au Japon</span><span>Accès réservé aux comptes autorisés</span></footer>
  </main>
</body>
</html>
