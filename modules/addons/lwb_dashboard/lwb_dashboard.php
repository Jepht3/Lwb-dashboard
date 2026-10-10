<?php
/**
 * Lwb-dashboard — tableau de bord d'administration LEWEBMAX pour WHMCS.
 *
 * Encaissements, revenu récurrent, impayés par ancienneté, renouvellements,
 * domaines à risque, commandes, paiements, tickets et meilleurs clients,
 * sur une seule page, plus un widget sur l'accueil de l'administration.
 * Lecture seule : le module ne modifie aucune donnée.
 *
 * Installation : copier le dossier dans /modules/addons/ de WHMCS, puis
 * Configuration > Modules complémentaires > Lwb-dashboard > Activer.
 */

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

require_once __DIR__ . '/lib/data.php';
require_once __DIR__ . '/lib/view.php';

function lwb_dashboard_config()
{
    return [
        'name' => 'Lwb-dashboard',
        'description' => 'Tableau de bord LEWEBMAX : encaissements, revenu récurrent, impayés, renouvellements, domaines à risque, commandes et tickets sur une seule page.',
        'version' => '1.0',
        'author' => 'LEWEBMAX',
        'fields' => [],
    ];
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
        echo lwbd_render(lwbd_collect((int) ($_GET['p'] ?? 12)), $vars['modulelink']);
    } catch (\Throwable $e) {
        logActivity('Lwb-dashboard : ' . $e->getMessage());
        echo '<div class="alert alert-danger">Le tableau de bord n\'a pas pu être chargé : ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
