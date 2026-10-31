# Utiliser l’espace d’édition

L’espace d’édition se trouve à **https://blog.audricrosier.be/admin/**. Il nécessite une connexion HTTPS et un compte créé par un administrateur.

## Rôles

- **Éditeur** : crée un article en français. Il peut aussi garder l’article en brouillon.
- **Traducteur** : consulte un article français et crée ou met à jour sa traduction japonaise.
- **Admin** : cumule tous les droits, gère les comptes et rôles, crée des articles et supprime un article avec ses traductions.

Un compte peut recevoir plusieurs rôles.

## Connexion et publication

L’administrateur remet à chaque nouveau compte un mot de passe temporaire affiché une seule fois. Au premier accès, l’utilisateur doit choisir son propre mot de passe.

Quand un article ou une traduction est enregistré, l’espace d’édition crée un commit sur `main` dans GitHub. L’hébergement le déploie ensuite selon la configuration de déploiement du site.

La procédure de configuration initiale du serveur est dans [GUIDE-ADMIN.md](GUIDE-ADMIN.md).
