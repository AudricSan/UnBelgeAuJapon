# AGENTS.md

This file provides guidance to Codex (claude.ai/code) when working with code in this repository.

## Project Overview

**Ma vie au Japon** is a multilingual personal blog built with [Hugo](https://gohugo.io/) using the **PaperMod** theme. The site covers life as a Belgian expat in Japan: visa procedures, marriage, residency, and personal accounts.

## Commands

```bash
# Start the local development server (live reload)
hugo server

# Build the site into public/
hugo

# Create a new article
hugo new Blog/<section>/<slug>/index.fr.md

# Build with drafts visible
hugo server -D
```

The site is also accessible via XAMPP at `D:\audri\Xamp\htdocs\MaVieAuJapon\public\`.

## Content Architecture

### Languages
- **French** (`fr`) — default language, weight 1
- **Japanese** (`ja`) — weight 2
- English (`en`) exists in some articles but is not declared as an official language in `hugo.toml`

### Content structure
```
content/
  _index.fr.md          # Home page (fr)
  _index.ja.md          # Home page (ja)
  Blog/
    <article-slug>/
      index.fr.md       # French version
      index.ja.md       # Japanese version
      index.en.md       # English version (optional)
    info/
      <article-slug>/
        index.fr.md
        ...
```

Each article lives in its own folder under `content/Blog/`. Multilingual variants share the same folder and differ only by the language suffix in the filename (`index.fr.md`, `index.ja.md`, `index.en.md`).

### Frontmatter convention
Articles use **YAML frontmatter** (`---`). Key fields:
```yaml
---
title: '...'
date: 2025-01-01
categories:
  - 'Catégorie'
tags:
  - 'mot-clé'
slug: url-slug
description: '...'
---
```

The archetype at `archetypes/default.md` uses TOML (`+++`) — prefer YAML for new content to match existing articles.

## Theme

PaperMod is installed as a Git submodule under `themes/PaperMod/`. Do not edit files inside `themes/` directly; override them via `layouts/` or `assets/` at the project root.

## RULES — NEVER TOUCH
**NEVER** modify, create, or delete files inside:
- `themes/` — PaperMod submodule, read-only
- `public/` — generated output, read-only

## Migration Script

`migration.ps1` is a PowerShell script that was used to migrate Grav CMS content to Hugo format. It strips Grav-specific frontmatter, normalises taxonomy fields (`category:` → `categories:`), and produces multilingual `index.<lang>.md` files. It reads from `OLD/` and writes to `content/` — only useful if re-running the migration.
