# Règles de design — blog « Un Belge au Japon » (Hugo)

Ce fichier est destiné à un agent qui travaille sur le thème Hugo de blog.audricrosier.be. Suivez-le à la lettre pour le CSS, les gabarits et le contenu. En cas de doute, ne devinez pas : demandez.

## 1. Principes

- Éditorial avant décoratif. La typographie porte l'identité, les ornements restent rares.
- Une couleur d'accent, pas un fond. Le terracotta signe, il ne remplit pas (10 % max).
- Le manuscrit est une annotation, jamais un texte. Caveat sert à une accroche ou un soulignement, jamais à une phrase entière.
- Photo réelle ou rien. Uniquement des photographies personnelles, jamais d'images de stock ni générées.
- Même système partout : blog, Instagram et Threads partagent couleurs, typographies et étiquettes.
- Vie privée. Ne jamais publier la ville de résidence (dire « préfecture de Saitama »), ni le prénom de l'épouse, ni de nom de famille. Dire « ma femme » ou « mon épouse ». Dire « de Bruxelles à Tokyo » pour l'arc d'expatriation. Aucune mention publique d'un usage d'IA.

## 2. Couleurs (variables CSS)

| Variable        | HEX     | Rôle                                               | Proportion |
| --------------- | ------- | -------------------------------------------------- | ---------- |
| `--creme`       | #F2EBDD | Fond principal                                     | 60 %       |
| `--anthracite`  | #242321 | Titres et texte courant                            | 25 %       |
| `--terracotta`  | #B65C45 | Accents, étiquettes, CTA                           | 10 %       |
| `--beige`       | #D9C7B0 | Encadrés, fonds secondaires                        | 5 %        |
| `--sauge`       | #6B7F72 | Touches ponctuelles : tag Guide, nature, transport | rare       |
| `--blanc-casse` | #F8F3E9 | Texte sur photo voilée ou sur terracotta           | —          |
| `--voile`       | #3A332C | Voile sombre sur les photos, opacité 55 %          | —          |
| `--gris-pied`   | #8A8175 | Pied de page, mentions discrètes                   | —          |

Déclarez-les dans `assets/css/main.css` sous `:root`. N'utilisez aucune autre couleur sans l'ajouter ici.

## 3. Typographies

- **Playfair Display** (500, 700, 900) : titres, traitement éditorial. C'est la police d'identité. Variable `--font-titre`.
- **Inter** (400, 600, 800) : textes, légendes, interface. Variable `--font-texte`.
- **Caveat** (600, 700) : touche manuscrite. Variable `--font-manuscrit`. Une accroche ou un soulignement par visuel au maximum.
- **Noto Serif JP** (600, 900) : japonais d'affiche, toujours apparié à Playfair. Variable `--font-japonais`.

Échelle de référence (canevas 1080 × 1350, à transposer en rem sur le web) : titre d'affiche 118, grand japonais 190, titre de slide 62, corps 40 (interligne 57), bandeau 56, pastille 31, tag 27, pied 29.

## 4. Accessibilité (contrastes mesurés, à respecter)

- Anthracite sur crème : 13,2:1. Anthracite sur beige : 9,5:1. Ce sont les paires pour tout texte courant.
- Terracotta sur crème, et crème sur terracotta : 3,8:1. Réservé aux grands titres et aux textes en grand format, jamais au corps de texte.
- Sauge sur crème : 3,6:1. Réservé aux tags et aux grands libellés en gras.
- Texte sur photo : blanc cassé sur voile sombre, toujours.
- Focus visible sur tous les éléments interactifs :
  ```css
  :focus-visible { outline: 2px solid var(--terracotta); outline-offset: 2px; }
  ```
- Ne jamais transmettre une information par la couleur seule. Chaque catégorie porte un libellé en texte. Une comparaison est intitulée « Belgique » et « Japon » en toutes lettres.

## 5. Étiquettes et tags de catégorie

Chaque catégorie a un libellé en texte et une classe CSS :

| Catégorie   | Classe             | Style                                |
| ----------- | ------------------ | ------------------------------------ |
| Récit       | `.tag-recit`       | fond terracotta, texte crème         |
| Guide       | `.tag-guide`       | fond sauge, texte blanc cassé        |
| Comparaison | `.tag-comparaison` | fond beige, texte anthracite         |
| Conseil     | `.tag-conseil`     | contour sauge, texte sauge           |
| Quotidien   | `.tag-quotidien`   | contour anthracite, texte anthracite |

Dans le front matter d'un article : `categories: ["Guide"]`. Le gabarit ajoute la classe correspondante.

Étiquettes « coup de pinceau » : fond terracotta, texte crème en capitales. Elles servent au nom du compte et aux numéros de post.

## 6. Gabarits de contenu (trois modèles)

1. **Récit / Article** : photo plein cadre avec voile sombre, titre Playfair blanc cassé en bas. Ne jamais mettre d'arrondi sur une photo.
2. **Guide / Conseils** : fond crème, titre dont le mot-clé est en terracotta, liste structurée, bouton terracotta vers le blog.
3. **Comparaison / Réflexion** : deux colonnes, Belgique en anthracite et Japon en terracotta. Bandeau anthracite final avec une phrase manuscrite.

## 7. Éléments graphiques

- Soleil rouge texturé : un seul asset, réutilisé partout. Favicon du site : le soleil seul (variante simple).
- Logo complet : sceau terracotta (torii, Fuji, cerisier crème) avec « UN BELGE / AU JAPON ». Ne pas le déformer ni le recolorer.
- Soulignements et flèches manuscrits en terracotta ou anthracite, avec parcimonie.
- Aucune image de stock, aucune image générée.

## 8. Règles de rédaction

- Première personne, détails vécus uniquement. Rien d'inventé.
- Titres en français, accents et typographie soignés.

## 9. Ce qui n'est pas défini

- Rayons d'angle : non définis dans la charte. Ne pas en ajouter sans validation.
- Espacements autres que les marges : 56 px en couverture, 70 px en contenu. Pour le reste, s'en tenir au rythme visuel de la charte.

Si une règle manque ou se contredit, signalez-le au lieu de choisir seul.
