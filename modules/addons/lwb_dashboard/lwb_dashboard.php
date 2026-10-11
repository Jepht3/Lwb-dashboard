<?php
/**
 * Lwb-dashboard — tableau de bord d'administration LEWEBMAX pour WHMCS.
 *
 * Encaissements, revenu récurrent, impayés par ancienneté, renouvellements,
 * domaines à risque, commandes, paiements, tickets et meilleurs clients,
 * sur une seule page, plus un widget sur l'accueil de l'administration.
 * Lecture seule, sauf le bouton « Relancer » qui envoie un e-mail de relance
 * sur demande de l'administrateur.
 *
 * Installation : copier le dossier dans /modules/addons/ de WHMCS, puis
 * Configuration > Modules complémentaires > Lwb-dashboard > Activer.
 */

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

require_once __DIR__ . '/lib/data.php';
require_once __DIR__ . '/lib/health.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/view.php';

function lwb_dashboard_config()
{
    return [
        'name' => 'Lwb-dashboard',
        'description' => 'Tableau de bord LEWEBMAX : encaissements, revenu récurrent, impayés, renouvellements, domaines à risque, commandes et tickets sur une seule page.',
        'version' => '1.1',
        'author' => 'LEWEBMAX',
        'fields' => [
            'goal' => [
                'FriendlyName' => 'Objectif mensuel (USD)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '500',
                'Description' => 'Montant à encaisser chaque mois. Laissez vide pour masquer la jauge.',
            ],
            'period' => [
                'FriendlyName' => 'Période par défaut',
                'Type' => 'dropdown',
                'Options' => ['6' => '6 mois', '12' => '12 mois', '24' => '24 mois'],
                'Default' => '12',
                'Description' => 'Période affichée à l\'ouverture du tableau de bord.',
            ],
            'homepage' => [
                'FriendlyName' => 'Page d\'accueil de l\'admin',
                'Type' => 'yesno',
                'Description' => 'Ouvrir ce tableau de bord à la place de l\'accueil WHMCS (lien « Accueil WHMCS classique » toujours disponible).',
            ],
        ],
    ];
}

function lwb_dashboard_upgrade($vars)
{
    // 1.0 -> 1.1 : rien à migrer (les réglages ont des valeurs par défaut).
}

function lwb_dashboard_activate()
{
    return ['status' => 'success', 'description' => 'Tableau de bord activé : Modules complémentaires > Lwb-dashboard, et widget sur l\'accueil de l\'administration.'];
}

function lwb_dashboard_deactivate()
{
    return ['status' => 'success', 'description' => 'Tableau de bord désactivé.'];
}

function lwb_dashboard_output($vars)
{
    try {
        $flash = lwbd_handle_actions();
        $d = lwbd_collect((int) ($_GET['p'] ?? ($vars['period'] ?? 12)));
        echo lwbd_render($d, $vars['modulelink'], [
            'flash' => $flash,
            'token' => function_exists('generate_token') ? generate_token('form') : '',
            'forecast' => lwbd_forecast($d, (float) str_replace(',', '.', (string) ($vars['goal'] ?? 0))),
            'health' => lwbd_health(),
        ]);
    } catch (\Throwable $e) {
        logActivity('Lwb-dashboard : ' . $e->getMessage());
        echo '<div class="alert alert-danger">Le tableau de bord n\'a pas pu être chargé : ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
