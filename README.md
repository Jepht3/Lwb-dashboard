# Lwb-dashboard

Tableau de bord d'administration pour WHMCS 8, aux couleurs de LEWEBMAX. Il réunit sur une seule page tout ce qu'il faut suivre au quotidien : argent encaissé, impayés, renouvellements, domaines à risque, commandes et tickets.

![Aperçu sur ordinateur](docs/apercu-bureau.png)

## Ce qu'il affiche

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

L'extension ne modifie aucune donnée. Seule exception : le bouton « Relancer », qui envoie un e-mail à la demande de l'administrateur, protégé par le jeton de sécurité de WHMCS. Les graphiques sont en SVG généré côté serveur, sans bibliothèque JavaScript externe.

## Installation

1. Copier le dossier `modules/addons/lwb_dashboard` dans `modules/addons/` de WHMCS.
2. Dans l'administration, aller dans **Configuration › Modules complémentaires**, puis **Activer** sur « Lwb-dashboard ».
3. Choisir les rôles d'administrateur autorisés à voir le tableau de bord.
4. Ouvrir **Modules complémentaires › Lwb-dashboard**. Le widget apparaît sur l'accueil de l'administration.

## Réglages

Dans **Configuration › Modules complémentaires › Lwb-dashboard › Configurer** :

| Réglage | Rôle |
|---|---|
| Objectif mensuel (USD) | Montant visé chaque mois (jauge « Objectif du mois »). Laissez vide pour la masquer. |
| Période par défaut | 6, 12 ou 24 mois à l'ouverture. |
| Page d'accueil de l'admin | Ouvre le tableau de bord après la connexion, à la place de l'accueil WHMCS. Le lien « Accueil WHMCS classique » reste disponible en haut de la page. |

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
├── lib/actions.php     bouton « Relancer » (jeton CSRF WHMCS)
├── lib/view.php        affichage HTML et graphiques SVG
└── assets/             styles et info-bulles
```

## Aperçu mobile

![Aperçu sur mobile](docs/apercu-mobile.png)

Les captures utilisent des données d'exemple.

## Historique

- **1.1** :
  - réglages : objectif, période, page d'accueil ;
  - objectif du mois et prévisions ;
  - santé du système ;
  - bouton « Relancer » ;
  - exports CSV ;
  - affichage de toutes les échéances à 30 jours ;
  - alerte urgente dans le widget.
- **1.0** : première version.
