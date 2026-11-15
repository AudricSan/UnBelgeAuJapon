<div align="center">

# 🗾 Un Belge au Japon

**Vivre au Japon : guide, expérience et conseils d'expat**

Visa, mariage, résidence, démarches administratives, vie quotidienne : le récit d'un Belge installé au Japon, en français et en japonais.

[![Build & deploy](https://github.com/AudricSan/UnBelgeAuJapon/actions/workflows/deploy.yml/badge.svg)](https://github.com/AudricSan/UnBelgeAuJapon/actions/workflows/deploy.yml)
![Hugo](https://img.shields.io/badge/Hugo-0.169.0%20Extended-ff4088?logo=hugo&logoColor=white)
![Thème](https://img.shields.io/badge/th%C3%A8me-PaperMod-blue)
![Langues](https://img.shields.io/badge/langues-FR%20%7C%20JA-green)

**[🌐 blog.audricrosier.be](https://blog.audricrosier.be/)** · **[🇯🇵 日本語版](https://blog.audricrosier.be/ja/)**

</div>

---

## ✨ Aperçu

- **Bilingue** : français (langue par défaut) et japonais, un dossier par article.
- **Rubriques** : *histoire*, *info* et *quotidien*, plus catégories et tags dans les deux langues.
- **Site statique** généré par [Hugo](https://gohugo.io/) avec le thème [PaperMod](https://github.com/adityatelange/hugo-PaperMod) personnalisé (SCSS, charte graphique claire).
- **Publication automatique** : un `git push` suffit, GitHub Actions compile et envoie le site sur OVH.
- **Espace d'administration** PHP optionnel pour rédiger sans toucher au code.

## 🧰 Prérequis

| Outil | Version | Rôle |
|---|---|---|
| [Hugo Extended](https://gohugo.io/installation/) | **0.169.0** | génération du site |
| [Dart Sass](https://sass-lang.com/install/) | 1.107.1 (binaire natif) | compilation du SCSS (`css.Sass`) |
| Git | récent | versionnement, sous-module du thème |
| Python 3 | 3.10+ | scripts de validation et de déploiement |

## 🚀 Démarrage rapide

```powershell
# 1. Cloner avec le thème (sous-module)
git clone --recurse-submodules https://github.com/AudricSan/UnBelgeAuJapon.git
cd UnBelgeAuJapon

# 2. Serveur local avec rechargement automatique
hugo server            # ajouter -D pour voir les brouillons

# 3. Build de production (identique à la CI)
hugo build --minify --gc --cleanDestinationDir
python scripts/validate_build.py public
```

Thème absent après un clone classique : `git submodule update --init --recursive`.

## ✍️ Écrire un article

Chaque article vit dans son dossier ; les langues partagent le dossier et ne diffèrent que par le suffixe.

```
content/Blog/<rubrique>/<slug>/
├── index.fr.md
└── index.ja.md
```

```powershell
hugo new Blog/info/mon-article/index.fr.md
```

```yaml
---
title: 'Titre de l’article'
date: 2025-01-01
categories:
  - 'Catégorie'
tags:
  - 'mot-clé'
slug: mon-article
description: 'Résumé pour le référencement'
---
```

Le frontmatter est en **YAML** (`---`). Voir [GUIDE-EDITEUR.md](GUIDE-EDITEUR.md) pour l'espace d'édition et [charte-blog-hugo.md](charte-blog-hugo.md) pour la charte éditoriale.

## 🗂️ Structure du dépôt

```
.
├── content/            # Articles et pages (FR/JA)
├── layouts/            # Surcharges du thème (head, header, footer, shortcodes…)
├── assets/             # SCSS et CSS étendus
├── static/             # Fichiers copiés tels quels (images, admin PHP)
├── i18n/ · data/       # Traductions et données
├── themes/PaperMod/    # Thème (sous-module Git, lecture seule)
├── scripts/
│   ├── validate_build.py   # contrôles du build
│   └── deploy_sftp.py      # synchronisation SFTP vers OVH
├── .github/workflows/deploy.yml
├── hugo.toml           # configuration du site
└── DEPLOYMENT.md       # documentation du déploiement
```

> Ne modifie jamais `themes/` ni `public/` : personnalise via `layouts/` et `assets/`, et laisse `public/` être régénéré.

## 🔄 Publication automatique

```powershell
git add .
git commit -m "Update blog"
git push origin main
```

À chaque push sur `main`, GitHub Actions :

1. récupère le dépôt et le thème ;
2. installe Hugo Extended 0.169.0 et Dart Sass ;
3. compile en production (`hugo build --minify --gc --cleanDestinationDir`) ;
4. **valide le build** (pages FR/JA, flux RSS, sitemaps, feuille de style, ressources référencées) ;
5. envoie en **SFTP** vers OVH uniquement les fichiers modifiés, et ne supprime que les fichiers qu'il a lui-même déployés.

Le déploiement est refusé si un secret manque, si l'empreinte du serveur ne correspond pas, ou si le nombre de suppressions est anormal. Lancement manuel en simulation : onglet **Actions → Run workflow** (`dry_run` coché par défaut).

Détails (secrets, maintenance, retour arrière, sécurité) : **[DEPLOYMENT.md](DEPLOYMENT.md)**.

## 🔐 Sécurité

- Identifiants uniquement dans les **GitHub Actions Secrets**, jamais dans le dépôt ; `OVH.xml`, `admin-config.php` et la base SQLite sont ignorés par Git.
- Permissions du workflow limitées à la lecture du dépôt.
- Déploiement restreint au dossier du blog, avec vérification stricte de l'hôte SSH.

## 📚 Documentation

| Fichier | Contenu |
|---|---|
| [DEPLOYMENT.md](DEPLOYMENT.md) | CI/CD, secrets, maintenance, retour arrière |
| [GUIDE-EDITEUR.md](GUIDE-EDITEUR.md) | utilisation de l'espace d'édition |
| [GUIDE-ADMIN.md](GUIDE-ADMIN.md) | configuration du serveur et des accès |
| [charte-blog-hugo.md](charte-blog-hugo.md) | charte éditoriale et graphique |
| [CLAUDE.md](CLAUDE.md) | conventions du projet pour l'assistant de code |

## 📄 Licence

Voir [LICENSE](LICENSE). Les contenus rédactionnels (textes, photos) restent la propriété de leur auteur, AudricSan.
