# Lwb-dashboard

Tableau de bord **et thème complet** pour l'administration de WHMCS 8, aux couleurs de LEWEBMAX.

- **Le tableau de bord** réunit sur une seule page tout ce qu'il faut suivre au quotidien : argent encaissé, impayés, renouvellements, domaines à risque, commandes et tickets.
- **Le thème « Lwb-admin »** modernise toute l'administration :
  - menu latéral bleu nuit à icônes et barre de recherche en haut ;
  - menus contextuels épurés ;
  - tableaux, formulaires, onglets, boutons, statuts et fenêtres redessinés ;
  - page de connexion à la marque LEWEBMAX ;
  - version mobile avec menu coulissant.

![Aperçu sur ordinateur](docs/apercu-bureau.png)

## Thème de l'administration (Lwb-admin)

![Page de connexion](docs/apercu-connexion.png)

Le thème s'applique par-dessus le thème WHMCS d'origine (Blend), sans le modifier. Il a été vérifié page par page sur les 239 pages de l'administration WHMCS 8.13 (menus, réglages, rapports, journaux, extensions) :

- il se charge sur toutes les pages de l'administration ;
- un interrupteur dans **Réglages › Thème de l'administration** le désactive instantanément ;
- le bouton à gauche du titre, dans la barre du haut, masque ou affiche le menu de la section, pour travailler en pleine largeur sur les grands tableaux.

Trois écrans autonomes de WHMCS gardent leur propre design : WHMCS Connect, l'assistant « Getting started » et la page d'erreur.

La page de connexion est habillée par une ligne ajoutée à `login.tpl`. WHMCS remplace ce fichier à chaque mise à jour, et l'extension remet la ligne automatiquement à la connexion suivante.

La mention de copyright de WHMCS reste affichée, comme l'exige la licence.

## Ce qu'affiche le tableau de bord

- **8 indicateurs** :
  - encaissé ce mois, comparé à la même date le mois précédent ;
  - revenu récurrent mensuel ;
  - impayés, avec la part en retard ;
  - commandes en attente ;
  - clients actifs ;
  - services actifs ;
  - domaines actifs, avec ceux en période de grâce ;
  - tickets à traiter.
- **Encaissements nets par mois** sur 6, 12 ou 24 mois, avec info-bulle au survol et tableau des données.
- **Impayés par ancienneté** (pas encore échus, 1–30 j, 31–90 j, plus de 90 j) et plus gros soldes.
- **Renouvellements des 30 prochains jours**, avec l'état de la facture : payée, impayée ou pas encore facturée.
- **Domaines à surveiller** :
  - en période de grâce ou en rédemption ;
  - payés mais pas renouvelés chez le registraire ;
  - expirant dans 15 jours ;
  - sans registraire.
- **Revenus par activité** (catégorie de produits, domaines, pénalités de retard…) et **par moyen de paiement**.
- **Dernières commandes**, **derniers paiements**, **tickets à traiter** et **meilleurs clients**.
- **Objectif du mois** : jauge de progression, projection de fin de mois, factures dues d'ici la fin du mois, renouvellements à encaisser sous 30 jours.
- **Santé du système** :
  - dernier passage du cron et des tâches quotidiennes ;
  - échecs d'enregistrement ou de renouvellement de domaines, avec une alerte dédiée quand le crédit du registraire est épuisé ;
  - erreurs techniques des 7 derniers jours ;
  - services payés mais non activés ;
  - demandes de résiliation ;
  - extrait du journal d'activité.
- **Bouton « Relancer »** sur les plus gros impayés : envoie l'e-mail de relance adapté au retard, au plus une fois par jour et par facture.
- **Export CSV** (compatible Excel) des impayés, des renouvellements et des domaines à surveiller.
- Un **widget** « LEWEBMAX en un coup d'œil » sur l'accueil de l'administration, avec un bandeau en cas d'alerte urgente.

Chaque ligne renvoie vers la fiche correspondante : client, facture, service, domaine, commande ou ticket. La page s'adapte au mobile.

L'extension ne modifie aucune donnée. Seules exceptions : le bouton « Relancer », qui envoie un e-mail, et la page Réglages. Les deux agissent uniquement à la demande de l'administrateur et sont protégés par le jeton de sécurité de WHMCS. Les graphiques sont en SVG généré côté serveur, sans bibliothèque JavaScript externe.

## Installation

1. Copier le dossier `modules/addons/lwb_dashboard` dans `modules/addons/` de WHMCS.
2. Dans l'administration, aller dans **Configuration › Modules complémentaires**, puis **Activer** sur « Lwb-dashboard ».
3. Choisir les rôles d'administrateur autorisés à voir le tableau de bord.
4. Ouvrir **Modules complémentaires › Lwb-dashboard**. Le widget apparaît sur l'accueil de l'administration.

## Réglages

Le bouton **Réglages**, en haut du tableau de bord, ouvre une page dans le même design :

![Page Réglages](docs/apercu-reglages.png)

- **Objectif et période** :
  - objectif d'encaissement mensuel ; laissez le champ vide pour masquer la jauge ;
  - période affichée à l'ouverture : 6, 12 ou 24 mois.
- **Démarrage** : ouvre le tableau de bord après la connexion, à la place de l'accueil WHMCS. Le lien « Accueil WHMCS classique » reste disponible.
- **Thème de l'administration** : active ou désactive le thème Lwb-admin pour tous les administrateurs.
- **Sections affichées** : un interrupteur par carte pour masquer ce qui ne sert pas. Quand une carte est masquée, sa voisine prend toute la largeur.

Les administrateurs autorisés se choisissent toujours dans WHMCS (Configuration › Modules complémentaires), comme pour toute extension.

## Compatibilité

- WHMCS 8.x (testé sur 8.13), PHP 7.4 à 8.2.
- Fonctionne avec le thème d'administration par défaut « Blend ». Comme la page est une extension, elle n'est pas touchée par les mises à jour de WHMCS.

## Structure

```
modules/addons/lwb_dashboard/
├── lwb_dashboard.php   configuration, activation, page du module
├── hooks.php           widget de l'accueil, redirection vers le tableau de bord
├── lib/data.php        calcul des chiffres (requêtes en lecture seule)
├── lib/health.php      santé du système, objectif et prévisions
├── lib/actions.php     bouton « Relancer » et enregistrement des réglages (jeton CSRF WHMCS)
├── lib/view.php        affichage HTML et graphiques SVG
└── assets/             styles et info-bulles
```

## Aperçu mobile

![Aperçu sur mobile](docs/apercu-mobile.png)

Les captures utilisent des données d'exemple.

## Historique

- **1.3.1** : passe complète sur les 239 pages de l'administration ; menus latéraux plus fins, bouton pour masquer le menu de la section (mémorisé), entrée « Configuration » dans le menu, anciennes icônes WHMCS harmonisées, page de connexion ajustée sur mobile.
- **1.3** : thème Lwb-admin pour toute l'administration et la page de connexion, activable dans les Réglages.
- **1.2** : nouvelle page Réglages dans le design du tableau de bord (interrupteurs, choix de la période, sections à afficher). Les champs de l'ancienne page de configuration WHMCS sont supprimés.
- **1.1** :
  - réglages : objectif, période, page d'accueil ;
  - objectif du mois et prévisions ;
  - santé du système ;
  - bouton « Relancer » ;
  - exports CSV ;
  - affichage de toutes les échéances à 30 jours ;
  - alerte urgente dans le widget.
- **1.0** : première version.
