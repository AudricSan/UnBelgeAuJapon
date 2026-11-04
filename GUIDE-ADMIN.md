# Administration, statistiques et newsletter

Un seul petit module PHP + SQLite, sans service externe. Le site reste statique (Hugo) ; seuls trois dossiers sont dynamiques :

| Chemin | Rôle |
|---|---|
| `static/admin/` | Interface (`index.php`, `app.js`) et API (`api.php`). Le code est dans `admin/inc/` (fermé au web par `.htaccess`). |
| `static/api/hit.php` | Compteur de visites, appelé par `static/s.js`. |
| `static/api/newsletter.php` | Inscription, confirmation et désinscription publiques. |

## Ce que fait l'interface (`/admin/`)

- **Statistiques** : visiteurs, pages vues, pages les plus lues, sources, langues, appareils. Mesure sans cookie (identifiant anonyme recalculé chaque jour, IP jamais conservée), donc aucun bandeau de consentement. Tes propres visites sont exclues dès que tu te connectes à l'admin (`localStorage.mvaj-ignore`).
- **Articles** : liste, modification du Markdown brut (FR/JA), création, suppression. Chaque enregistrement est un commit sur `main` via l'API GitHub ; GitHub Actions compile et déploie en 2 à 3 minutes.
  Titre Google : ajoute `seo_title: 'Titre court'` dans l'en-tête de l'article ; il remplace `title` dans la balise `<title>` seulement (sans lui, le titre de l'article est utilisé).
- **Newsletter** : abonnés (inscription avec confirmation par e-mail), envoi d'un article choisi parmi ceux en ligne, e-mail de test, historique. L'envoi se fait par lots de 10 depuis le navigateur (compatible hébergement mutualisé).

## Configuration (une seule fois)

1. Copie `admin-config.example.php` vers `admin-config.php`, **hors du dossier public** (par défaut trois niveaux au-dessus de `admin/inc/`, soit à côté de `www/` sur OVH). Il n'est jamais versionné.
2. Génère les valeurs avec le PHP de XAMPP :
   ```powershell
   D:\audri\Xamp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"                           # security_key
   D:\audri\Xamp\php\php.exe -r "echo password_hash('ton-mot-de-passe', PASSWORD_DEFAULT), PHP_EOL;"  # admin_password_hash
   ```
3. Renseigne `admin_email`, le jeton GitHub (fine-grained, ce dépôt uniquement, **Contents : Read and write**) et le mot de passe SMTP.
4. La base SQLite se crée toute seule au premier accès (dossier privé, inscriptible). Les abonnés actifs de l'ancien panneau sont importés automatiquement.

Ancien format de configuration : `security_key`, `database`, `github` et `newsletter.smtp` sont conservés ; il suffit d'ajouter `admin_email`, `admin_password_hash` et `site_url`. Les clés `bootstrap_key`, `analytics` et les fournisseurs Brevo/Mailgun/SendGrid ne servent plus.

## Aperçu local

`hugo server` n'exécute pas PHP. Compile avec `hugo`, sers `public/` par XAMPP. Pour un test isolé : `php -S 127.0.0.1:8099 -t static` avec la variable `MVAJ_ADMIN_CONFIG` pointant vers un fichier de config de test.

## Sauvegarde

Tout ce qui est dynamique tient dans un seul fichier : `mvaj-admin.sqlite`. Copie-le de temps en temps ; les articles, eux, sont dans Git.

## Évolutions du schéma

Ajoute une entrée numérotée dans `$steps` de `migrate()` (`admin/inc/core.php`) : elle s'applique une seule fois, automatiquement.
