# Configurer l’espace d’édition

Le panneau utilise PHP, un fichier SQLite pour les comptes et un jeton GitHub conservé côté serveur pour enregistrer les articles. Il ne fonctionne pas avec le seul serveur statique `hugo server`.

## Prérequis

- PHP 8.1 ou plus récent avec `PDO_SQLITE` et `cURL`.
- HTTPS actif sur le domaine public.
- Un dossier persistant, hors de la racine publique, dans lequel PHP peut créer et modifier un fichier.
- Un jeton GitHub fine-grained limité au dépôt `AudricSan/Ma-vie-au-Japon`, avec **Contents: Read and write**.

Le pilote `PDO_SQLITE` permet à PHP d’utiliser SQLite. Le fichier de base de données doit être dans un dossier accessible en écriture par PHP ([documentation PDO_SQLITE](https://www.php.net/manual/en/ref.pdo-sqlite.php), [format du DSN SQLite](https://www.php.net/manual/en/pdo-sqlite.connection.php)). Le pilote SQLite est activé dans le PHP local de XAMPP; vérifie aussi sa disponibilité sur l’hébergement.

## Configuration privée

1. Copie `admin-config.example.php` vers `admin-config.php` et place ce fichier hors du dossier accessible par le Web. Par défaut, le panneau le cherche deux niveaux au-dessus de `admin/`.
2. La configuration crée `mvaj-admin.sqlite` à côté de `admin-config.php`. Le dossier parent doit être accessible en écriture par PHP et rester conservé lors des déploiements.
3. Dans `admin-config.php`, renseigne le jeton GitHub. Le propriétaire, le dépôt et la branche `main` sont déjà indiqués.
4. Génère deux secrets aléatoires distincts d’au moins 32 caractères pour `security_key` et `bootstrap_key`. Avec le PHP de XAMPP, tu peux en générer un à la fois avec :

   ```powershell
   D:\audri\Xamp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
   ```

Ne partage pas ces secrets et ne les ajoute jamais à GitHub. `admin-config.php` et le fichier SQLite local sont ignorés par Git.

## Première connexion

Après déploiement du code PHP et installation de la configuration privée, ouvre **https://blog.audricrosier.be/admin/**. La base SQLite et ses tables sont créées au premier accès. Utilise `bootstrap_key` pour créer le premier compte administrateur, puis remplace sa valeur par `''` dans la configuration privée.

L’admin crée ensuite les autres comptes et coche un ou plusieurs rôles. Le mot de passe temporaire s’affiche une seule fois; l’utilisateur devra le remplacer à sa première connexion.

## Déploiement et aperçu local

Les fichiers PHP sous `static/admin/` doivent être copiés par Hugo dans le dossier servi par Apache. Configure le déploiement OVH pour publier la sortie de Hugo et laisse PHP activé pour `admin/`. `hugo server` sert les fichiers, mais n’exécute pas les scripts PHP.

Pour l’aperçu local, compile le site avec `hugo`, puis utilise le VirtualHost XAMPP qui sert `public/` à `http://127.0.0.4/admin/`. La configuration locale se trouve à la racine du projet, hors de `public/`. Pour l’utilisation en ligne, installe `admin-config.php` et conserve `mvaj-admin.sqlite` dans un dossier privé et persistant sur l’hébergement.

Si Hugo signale que PaperMod est introuvable, rétablis d’abord le sous-module `themes/PaperMod` avant de compiler.
