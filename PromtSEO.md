# Audit et optimisation SEO complète du projet Hugo — Ma Vie au Japon

Tu travailles directement dans le projet Hugo situé dans le répertoire courant.

Site : https://blog.audricrosier.be/

Le site est multilingue :
- Français : `/fr/`
- Japonais : `/ja/`

## Objectif

Effectuer un audit SEO technique complet et améliorer la génération automatique des fichiers SEO par Hugo, sans modifier le contenu éditorial ni casser le fonctionnement du site.

## 1. Audit initial

Avant toute modification :

- Identifier la version de Hugo.
- Examiner `hugo.yaml`, `hugo.toml` ou autre configuration.
- Examiner la configuration multilingue.
- Examiner les layouts et templates SEO existants.
- Examiner les templates sitemap et robots.txt.
- Identifier les taxonomies.
- Identifier les paramètres `date`, `lastmod`, `publishDate` et `aliases`.
- Examiner les métadonnées HTML de chaque type de page.
- Vérifier les templates utilisés pour les articles français et japonais.

Présenter un diagnostic avant les modifications.

## 2. Sitemap XML

Optimiser la génération native Hugo.

Exigences :

- Conserver un sitemap index multilingue.
- Conserver les sitemaps français et japonais.
- Inclure toutes les pages éditoriales publiques et indexables.
- Inclure les catégories pertinentes.
- Évaluer la pertinence des pages de tags.
- Éviter les pages vides, inutiles ou dupliquées.
- Exclure les contenus en brouillon, privés ou explicitement non indexables.
- Utiliser des dates `lastmod` fiables.
- Ne jamais utiliser la date de compilation comme date de modification.
- Ne jamais fabriquer une date de modification.
- Respecter les URL canoniques.
- Conserver les URL absolues HTTPS.

Ne pas ajouter artificiellement des URL pour rendre le sitemap plus volumineux.

## 3. SEO multilingue

Vérifier et corriger :

- `hreflang="fr"`.
- `hreflang="ja"`.
- Les relations entre traductions.
- Les URL canoniques propres à chaque langue.
- Les éventuelles traductions manquantes.
- La cohérence des métadonnées linguistiques.

Ne pas déclarer deux pages comme traductions si leur correspondance n'est pas confirmée.

## 4. Robots.txt

Configurer la génération automatique de robots.txt.

Le fichier doit :

- Autoriser l'exploration des pages publiques.
- Ne pas bloquer les ressources CSS, JS et images nécessaires au rendu.
- Ne pas bloquer globalement les répertoires de contenu.
- Déclarer le sitemap principal.

Ne pas utiliser robots.txt pour masquer des pages qui doivent simplement avoir une directive noindex.

## 5. Métadonnées SEO

Vérifier et optimiser :

- Title unique par page.
- Meta description.
- Canonical.
- Meta robots.
- Open Graph.
- Twitter Cards.
- Langue du document HTML.
- Titres H1.
- Attributs ALT des images.

Ne pas écraser les descriptions personnalisées existantes.

## 6. Données structurées Schema.org

Implémenter des données JSON-LD pertinentes :

- WebSite.
- Organization ou Person selon les informations réellement disponibles.
- BlogPosting pour les articles.
- BreadcrumbList pour les fils d'Ariane.

Respecter les propriétés réellement disponibles dans le contenu.

Ne pas inventer d'avis, de notes, de coordonnées ou d'informations personnelles.

## 7. Images

Examiner les images du blog :

- Vérifier les attributs ALT.
- Identifier les images sans description.
- Vérifier les dimensions et les formats.
- Vérifier les métadonnées Open Graph.
- Évaluer la pertinence d'un sitemap images.

Ne pas modifier les images originales.

## 8. Vérifications finales

Exécuter une compilation de production :

`hugo --minify`

Vérifier automatiquement :

- Présence de sitemap.xml.
- Présence des deux sitemaps linguistiques.
- Validité XML.
- Absence d'URL en HTTP.
- Absence de doublons.
- Absence de pages brouillon.
- Cohérence des dates lastmod.
- Présence de robots.txt.
- Validité des URL canoniques.
- Cohérence des hreflang.
- Absence de balises meta description vides.

Produire un rapport avec :
- Modifications effectuées.
- Problèmes détectés.
- Problèmes corrigés.
- Problèmes restant à traiter.
- Nombre d'URL dans chaque sitemap.
- Résultat des validations.

## Contraintes importantes

- Ne supprimer aucun article.
- Ne modifier aucun texte éditorial.
- Ne modifier aucune URL publique existante sans nécessité démontrée.
- Ne pas installer de dépendance externe inutile.
- Préserver le fonctionnement du thème.
- Privilégier les fonctionnalités natives de Hugo.
- Ne pas dupliquer des fonctionnalités SEO déjà présentes.

Commencer par l'audit, puis effectuer les corrections nécessaires et terminer par une compilation et une validation complètes.