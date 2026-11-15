# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**Un Belge au Japon** (`https://blog.audricrosier.be/`, served at the root of an OVH subdomain) is a bilingual personal blog (French default, Japanese) about life as a Belgian expat in Japan. It is a [Hugo](https://gohugo.io/) static site using the **PaperMod** theme, plus a small PHP + SQLite module (admin, stats, newsletter) shipped inside `static/`. Content was migrated from Grav CMS long ago; the migration script and `OLD/` no longer exist.

## Commands

```bash
hugo server                 # dev server with live reload (does NOT execute PHP)
hugo server -D              # include drafts
hugo new Blog/<section>/<slug>/index.fr.md   # new article (no archetypes/ dir; write YAML frontmatter by hand)

# Same build + check as CI (Hugo Extended 0.169.0 + Dart Sass required for css.Sass)
hugo build --minify --gc --cleanDestinationDir
python scripts/validate_build.py public
```

There is no test suite or linter. `scripts/validate_build.py` (required files, min ~300 HTML pages, link/resource checks) is the only automated check.

To test the PHP admin/API locally: build with `hugo`, serve `public/` through XAMPP, or run `php -S 127.0.0.1:8099 -t static` with `MVAJ_ADMIN_CONFIG` pointing to a test config. PHP binary: `D:\audri\Xamp\php\php.exe`.

## Deployment (push to `main` = production)

`.github/workflows/deploy.yml`: build with Hugo → write `public/private/admin-config.php` from the `ADMIN_CONFIG_PHP` secret → `validate_build.py` → `scripts/deploy_sftp.py` (lftp over SFTP to OVH, SHA-256 `.deploy-manifest` so only changed files are sent and only manifest-known files are deleted; deletion cap `MAX_DELETIONS`). Changes limited to `*.md` docs (`DEPLOYMENT.md`, `GUIDE-*.md`, `README.md`, `CLAUDE.md`, `charte-blog-hugo.md`) do not trigger a deploy. Manual runs default to `dry_run`. Details in `DEPLOYMENT.md`.

## Architecture

### Content (`content/`)
Each article is a folder with one file per language: `index.fr.md`, `index.ja.md` (`index.en.md` exists occasionally but `en` is not declared in `hugo.toml`). Sections under `content/Blog/`: `info/` (administrative how-tos), `histoire/` (personal stories), `quotidien/`. Other top-level pages: `About`, `Contact`, `legal`, `privacy`, `tags`. Use YAML frontmatter (`title`, `date`, `categories`, `tags`, `slug`, `description`). Optional `seo_title` replaces `title` only in the `<title>` tag.

### Theme overrides
`themes/PaperMod/` is a Git submodule: never edit it (nor `public/`, which is generated and gitignored). Customize through root `layouts/` (`_default`, `Blog/list.html`, `partials/` incl. `head`/`header`/`footer`/`breadcrumbs`/`post_meta`, `partials/templates/{opengraph,schema_json}.html`, `shortcodes/contact-form.html`) and `assets/` (`scss/main.scss` + `scss/common`, `css/extended`). Design rules (palette, typography, privacy rules for content such as never naming the city of residence) are in `charte-blog-hugo.md` — follow it for CSS, templates and content.

### Dynamic PHP module (static files copied verbatim into `public/`)
- `static/admin/` — admin UI (`index.php`, `app.js`, `admin.css`), `api.php`, and `inc/{core,articles,stats,newsletter,mail}.php` (closed to the web by `.htaccess`). Editing an article from the admin commits to `main` through the GitHub API, which triggers the deploy workflow.
- `static/api/hit.php` + `static/s.js` — cookie-less visit counter; `static/api/newsletter.php` — public subscribe/confirm/unsubscribe.
- All dynamic state lives in one SQLite file, `mvaj-admin.sqlite`. Schema changes: add a numbered entry to `$steps` in `migrate()` (`static/admin/inc/core.php`).
- Config: `admin-config.php` (template: `admin-config.example.php`) is looked up in `private/` first, then site root, then its parent. Locally the real file lives in `static/private/` (gitignored) and is copied into `public/private/` by the build; in production it is injected from the `ADMIN_CONFIG_PHP` GitHub secret. `*.sqlite` and the config are gitignored and blocked by `.htaccess`.
- See `GUIDE-ADMIN.md` (setup, backup) and `GUIDE-EDITEUR.md`.

### Hugo config notes
`hugo.toml`: `defaultContentLanguageInSubdir = false` (FR at `/`, JA at `/ja/`), `mainSections = ["Blog"]`, light theme only (toggle disabled), per-language menus.
