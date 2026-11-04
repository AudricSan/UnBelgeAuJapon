# Déploiement automatique — MaVieAuJapon

Publication : `git push origin main` → GitHub Actions compile avec Hugo → envoi en SFTP sur OVH.

## 1. Architecture

| Élément                                             | Rôle                                                                                                                                                                                                                   |
| --------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Ton PC**                                          | Édition des sources, `git commit`, `git push`.                                                                                                                                                                         |
| **GitHub Actions** (`.github/workflows/deploy.yml`) | À chaque push sur `main` : checkout (thème PaperMod inclus), Hugo Extended 0.169.0, `hugo build --minify --gc --cleanDestinationDir`, validation (`scripts/validate_build.py`), envoi SFTP (`scripts/deploy_sftp.py`). |
| **Hugo**                                            | Génère `public/` (FR + JA, flux RSS, sitemaps, SCSS via Dart Sass embarqué). `public/` n'est jamais versionné.                                                                                                         |
| **OVH Perso**                                       | Reçoit uniquement le contenu de `public/` dans le dossier racine du sous-domaine `blog.audricrosier.be`.                                                                                                               |

`baseURL` = `https://blog.audricrosier.be/` : le blog est servi à la **racine d'un sous-domaine**, pas dans un sous-chemin `/blog`. Les URLs ne changent donc pas et aucun ajustement de `baseURL` n'est nécessaire. « `/blog` » désigne le dossier distant de ce sous-domaine : son chemin réel est à confirmer (§2.3).

### Pourquoi SFTP + manifeste, et pas rsync

D'après la documentation OVH, **SSH (donc rsync/scp) n'est proposé que sur les offres Pro et Performance** ; sur Perso on dispose du **FTP/FTPS et du SFTP** (à activer). Le déploiement utilise donc `lftp` en SFTP, avec un fichier `.deploy-manifest` (SHA-256 de chaque fichier) stocké dans le dossier distant :

- seuls les fichiers **nouveaux ou modifiés** sont envoyés ;
- seuls les fichiers présents dans le manifeste **précédent** et absents du nouveau build sont **supprimés** — un fichier inconnu (autre site, dossier manuel) n'est jamais touché ;
- le manifeste est écrit **en dernier** : si l'envoi échoue, il n'est pas mis à jour et un nouveau run reprend proprement ;
- le **premier déploiement** envoie tout et ne supprime rien. Les anciens fichiers déposés à la main restent donc en place : supprime-les une fois, à la main, si tu le souhaites.

> À vérifier chez toi : si OVH Perso refuse ou limite le SFTP (ou n'accepte pas l'authentification par clé, probable sur Perso), le workflow échoue **avant toute modification** avec un message explicite.

## 2. Configuration initiale (à faire une seule fois)

### 2.1 Activer le SFTP chez OVH
Espace client OVH → **Web Cloud → Hébergements → ton hébergement → FTP-SSH** → activer le SFTP pour l'utilisateur principal (guide OVH : « Hébergement web – Comment activer l'accès SFTP »). Note le **serveur** (`ftp.clusterXXX.hosting.ovh.net`), le **port** (22) et l'**identifiant**.

### 2.2 Empreinte du serveur (`DEPLOY_KNOWN_HOSTS`)
Depuis ton PC :
```powershell
ssh-keyscan -p 22 -t ed25519,rsa ftp.clusterXXX.hosting.ovh.net
```
Compare l'empreinte avec celle affichée par ta première connexion dans FileZilla/WinSCP (`ssh-keygen -lf` sur la ligne obtenue) avant de la copier. Le contenu complet des lignes `host ssh-ed25519 AAAA…` est la valeur du secret. Si `ssh-keyscan` ne répond pas (SFTP non actif), le workflow ne pourra pas se connecter.

### 2.3 Chemin distant (`DEPLOY_PATH`) — ne rien déployer avant confirmation
1. Manager OVH → **Multisite** : repère la ligne `blog.audricrosier.be`, colonne **Dossier racine** (ex. `blog`).
2. Connecte-toi en SFTP (WinSCP/FileZilla) et vérifie que ce dossier est bien celui qui contient le blog actuel (`index.html`, `assets/`, `ja/`…). Le chemin SFTP est relatif à la racine de l'hébergement : `blog` ou `www/blog`, selon ce que tu vois.
3. `DEPLOY_PATH` = ce chemin exact. **Jamais** la racine de l'hébergement ni `www` : le script les refuse, ainsi que `/`, `.`, `..`.

Le script ne crée pas le dossier cible : s'il n'existe pas, il s'arrête sans rien modifier.

⚠ **Administration PHP** : `public/admin/` est généré depuis `static/admin/`. `admin-config.php` se place à la racine du blog (à côté de `index.html`) ou dans son dossier parent ; `mvaj-admin.sqlite` est créé à côté. Le `.htaccess` racine (généré depuis `static/.htaccess`) interdit tout accès web à ces fichiers. Le déploiement ne les touche jamais, car il ne gère que des fichiers listés dans son manifeste.

### 2.4 Secrets GitHub
Dépôt → **Settings → Secrets and variables → Actions → New repository secret**.

| Secret               | Valeur                                    | Obligatoire         |
| -------------------- | ----------------------------------------- | ------------------- |
| `DEPLOY_HOST`        | Serveur SFTP OVH                          | oui                 |
| `DEPLOY_USER`        | Identifiant SFTP                          | oui                 |
| `DEPLOY_PORT`        | Port (22)                                 | non (22 par défaut) |
| `DEPLOY_PATH`        | Chemin physique du dossier du blog (§2.3) | oui                 |
| `DEPLOY_KNOWN_HOSTS` | Lignes `known_hosts` vérifiées (§2.2)     | oui                 |
| `DEPLOY_PASSWORD`    | Mot de passe SFTP                         | l'un des deux       |
| `DEPLOY_SSH_KEY`     | Clé privée dédiée (si OVH l'accepte)      | l'un des deux       |

Clé SSH (seulement si ton offre l'accepte) : `ssh-keygen -t ed25519 -f mvaj_deploy -C "github-deploy"`, installe `mvaj_deploy.pub` selon la doc OVH, mets le contenu de `mvaj_deploy` dans `DEPLOY_SSH_KEY`, puis supprime le fichier local.

Sans secret obligatoire, le déploiement est refusé. La vérification de l'hôte est toujours stricte (`StrictHostKeyChecking=yes`).

### 2.5 Thème PaperMod (point bloquant du dépôt actuel)
`themes/PaperMod` est déclaré dans `.gitmodules`, mais n'était **pas enregistré** dans l'index Git (`?? themes/`). Sans cela, GitHub ne peut pas récupérer le thème et le build échoue. Un `git add .` l'enregistre comme sous-module (Git affiche un avertissement « embedded repository » : normal ici). Vérifie avant de commit :
```powershell
git ls-files -s themes/PaperMod   # doit afficher : 160000 <sha> 0 themes/PaperMod
```

### 2.6 Premier lancement : toujours en simulation
1. Commit + push de ce dispositif (le run déclenché échouera proprement tant que les secrets manquent).
2. Une fois les secrets créés : **Actions → Build & deploy to OVH → Run workflow** avec `dry_run` coché (valeur par défaut). Lis dans les logs le plan (`N envoi(s), 0 suppression(s)`, contenu du dossier cible).
3. Si le plan est cohérent, relance sans `dry_run`.

## 3. Utilisation quotidienne
```powershell
git add .
git commit -m "Update blog"
git push origin main
```
Suivi : onglet **Actions** du dépôt. Les modifications limitées à `DEPLOYMENT.md`, `README.md`, `GUIDE-*.md`, `CLAUDE.md` ne déclenchent pas de déploiement.

Test local identique à la CI :
```powershell
hugo build --minify --gc --cleanDestinationDir
python scripts/validate_build.py public
```

## 4. Maintenance

- **Logs** : Actions → run → job `deploy` → chaque étape est dépliable. Un résumé est affiché en haut du run.
- **Relancer** : run → *Re-run all jobs*, ou *Run workflow* (le dossier distant est recalculé à partir du manifeste, un relancement est sûr).
- **Échec de compilation** : étape « Build de production ». Reproduis en local avec la commande du §3 (même version Hugo 0.169.0 Extended). Cause fréquente : thème absent (§2.5).
- **Échec de validation** : étape « Validation du build » ; le message nomme le fichier manquant ou la ressource introuvable.
- **Échec SSH/SFTP** : étape « Déploiement SFTP ». `secrets manquants` → §2.4 ; `connexion SFTP ou dossier introuvable` → SFTP non activé, mot de passe, port ou `DEPLOY_PATH` ; `Host key verification failed` → `DEPLOY_KNOWN_HOSTS` obsolète ou incorrect (ne jamais le désactiver, le régénérer et le vérifier).
- **Garde-fous** : > `MAX_DELETIONS` suppressions (200 par défaut, réglable au lancement manuel), ou un build contenant moins de la moitié des fichiers déployés avant, bloquent le déploiement. Un manifeste distant illisible le bloque aussi.
- **Publication manuelle de secours** : `hugo build --minify --gc --cleanDestinationDir`, puis envoi du contenu de `public/` via WinSCP/FileZilla dans le dossier du blog. Supprime ensuite `.deploy-manifest` côté serveur pour que le prochain run recrée un état propre (envoi complet, sans suppression).
- **Revenir en arrière** : `git revert <commit>` puis `git push` ; le workflow redéploie l'ancien état et supprime les fichiers ajoutés entre-temps. Évite `git reset --hard` + `push --force`.
- **Vérifier le site** : https://blog.audricrosier.be/ (FR), https://blog.audricrosier.be/ja/ (JA), `/sitemap.xml`, `/index.xml`, une page d'article, la feuille de style (onglet Réseau). Vide le cache du navigateur si besoin.

## 5. Sécurité et limites
- Secrets uniquement dans GitHub Actions Secrets ; rien dans le dépôt. Les valeurs sont masquées dans les logs. Le mot de passe et la clé transitent par un fichier temporaire/stdin, jamais dans la ligne de commande.
- Permissions du workflow : `contents: read` uniquement.
- Empreinte d'hôte vérifiée (`StrictHostKeyChecking=yes`) ; aucun contournement.
- Déploiement limité à `DEPLOY_PATH` ; chemins génériques/dangereux refusés ; dossier cible jamais créé automatiquement ; suppressions limitées aux fichiers connus du manifeste et plafonnées.
- Un seul déploiement à la fois (`concurrency`), sans annulation en cours d'envoi.
- Les actions GitHub sont épinglées par version majeure (`@v4`, `@v3`), pas par SHA ; pour un durcissement maximal, remplace-les par des SHA de commit.
- Limites OVH Perso : pas de SSH/rsync ; SFTP à activer ; l'authentification par clé n'est probablement pas disponible (mot de passe à renouveler régulièrement) ; les IP de GitHub changent, donc aucune restriction d'IP côté OVH n'est possible.
- Non testé de bout en bout : aucune connexion à OVH n'a été faite (identifiants et chemin inconnus). Le build, la validation et la logique de plan ont été testés en local ; `lftp` n'a pas pu être exécuté sur ce poste Windows.
