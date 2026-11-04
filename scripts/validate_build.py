#!/usr/bin/env python3
"""Valide le contenu de public/ après `hugo build`.

Échoue (code 1) si le build est incomplet ou incohérent. Utilisable en local
et dans GitHub Actions :

    python3 scripts/validate_build.py [public_dir]
"""
import re
import sys
from pathlib import Path
from urllib.parse import unquote, urlparse

BASE_URL = "https://blog.audricrosier.be/"
MIN_HTML_PAGES = 300  # build actuel : ~500 pages HTML (FR + JA)

REQUIRED_FILES = [
    "index.html",
    "404.html",
    "index.xml",
    "sitemap.xml",
    "fr/sitemap.xml",
    "ja/index.html",
    "ja/index.xml",
    "ja/sitemap.xml",
    "blog/index.html",
    "ja/blog/index.html",
    "categories/index.html",
    "ja/categories/index.html",
    "tags/index.html",
    "ja/tags/index.html",
    "about/index.html",
    "contact/index.html",
    "mentions-legales/index.html",
    "confidentialite/index.html",
    ".htaccess",
    "admin/index.php",
    "admin/api.php",
    "admin/app.js",
    "admin/inc/core.php",
    "api/hit.php",
    "api/newsletter.php",
    "s.js",
]

errors: list[str] = []
warnings: list[str] = []


def fail(msg: str) -> None:
    errors.append(msg)


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8", errors="replace")


def main() -> int:
    public = Path(sys.argv[1] if len(sys.argv) > 1 else "public").resolve()
    if not public.is_dir():
        print(f"ERREUR : dossier introuvable : {public}")
        return 1

    # 1. Fichiers indispensables, non vides
    for rel in REQUIRED_FILES:
        f = public / rel
        if not f.is_file():
            fail(f"fichier manquant : {rel}")
        elif f.stat().st_size == 0:
            fail(f"fichier vide : {rel}")

    html_files = sorted(public.rglob("*.html"))
    if len(html_files) < MIN_HTML_PAGES:
        fail(f"seulement {len(html_files)} pages HTML (minimum attendu : {MIN_HTML_PAGES})")

    # 2. Articles dans les deux langues
    for lang_root, label in (("blog", "FR"), ("ja/blog", "JA")):
        posts = list((public / lang_root).glob("*/*/index.html"))
        if len(posts) < 10:
            fail(f"{label} : seulement {len(posts)} articles sous {lang_root}/")

    # 3. Feuille de style fingerprintée, non vide
    css = list((public / "assets" / "css").glob("stylesheet.*.css"))
    if not css:
        fail("assets/css/stylesheet.*.css absent (compilation SCSS en échec ?)")
    elif any(c.stat().st_size < 5000 for c in css):
        fail("feuille de style anormalement petite")

    # 4. Flux RSS et sitemaps
    for rss in ("index.xml", "ja/index.xml"):  # blog/index.xml est vide dans le site actuel
        f = public / rss
        if f.is_file() and "<item>" not in read(f):
            fail(f"flux RSS sans article : {rss}")
    sitemap = public / "sitemap.xml"
    if sitemap.is_file():
        s = read(sitemap)
        if "<sitemap>" not in s or BASE_URL not in s:
            fail("sitemap.xml invalide ou sans baseURL de production")
    for sm in ("fr/sitemap.xml", "ja/sitemap.xml"):
        f = public / sm
        if f.is_file() and f"<loc>{BASE_URL}" not in read(f):
            fail(f"{sm} ne contient aucune URL de production")

    # 5. Pages d'accueil : baseURL et langue
    for label, f, lang in (("FR", public / "index.html", "fr"), ("JA", public / "ja" / "index.html", "ja")):
        if f.is_file():
            t = read(f)
            if BASE_URL not in t:
                fail(f"{label} : page d'accueil sans baseURL de production")
            if not re.search(r'<html[^>]*lang="?' + lang, t):
                fail(f"{label} : attribut lang incorrect sur la page d'accueil")

    # 6. Toutes les pages : résidus de template, URLs locales, ressources absentes
    link_re = re.compile(r'(?:href|src)="([^"#?]+)')
    missing_assets: dict[str, str] = {}
    missing_pages: dict[str, str] = {}
    for f in html_files:
        t = read(f)
        rel = f.relative_to(public).as_posix()
        if "ZgotmplZ" in t:
            fail(f"résidu de template (ZgotmplZ) dans {rel}")
        if re.search(r"https?://(localhost|127\.0\.0\.\d+)", t):
            fail(f"URL locale dans {rel}")
        for ref in link_re.findall(t):
            ref = unquote(ref)
            if ref.startswith(BASE_URL):
                path = ref[len(BASE_URL):]
            elif ref.startswith("/") and not ref.startswith("//"):
                path = ref[1:]
            else:
                continue
            target = public / path
            if target.exists() or (target / "index.html").exists():
                continue
            ext = Path(urlparse(ref).path).suffix.lower()
            bucket = missing_pages if ext in ("", ".html") else missing_assets
            bucket.setdefault(ref, rel)

    for ref, src in sorted(missing_assets.items()):
        fail(f"ressource référencée mais absente : {ref} (dans {src})")
    for ref, src in sorted(missing_pages.items()):
        warnings.append(f"lien interne sans page : {ref} (dans {src})")

    total = sum(1 for p in public.rglob("*") if p.is_file())
    print(f"public/ : {total} fichiers, {len(html_files)} pages HTML")
    for w in warnings[:25]:
        print(f"AVERTISSEMENT : {w}")
    if len(warnings) > 25:
        print(f"AVERTISSEMENT : … et {len(warnings) - 25} autres liens internes sans page")
    for e in errors:
        print(f"ERREUR : {e}")
    if errors:
        print(f"\nValidation ÉCHOUÉE ({len(errors)} erreur(s)).")
        return 1
    print("Validation réussie.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
