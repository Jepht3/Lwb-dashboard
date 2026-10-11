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
        'version' => '1.3.1',
        'author' => 'LEWEBMAX',
        // Les réglages se font sur la page « Réglages » du tableau de bord, dans le même design.
        'fields' => [],
    ];
}

function lwb_dashboard_upgrade($vars)
{
    // Rien à migrer : les réglages existants (objectif, période, accueil) sont repris tels quels.
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
        $set = lwbd_settings();
        $token = function_exists('generate_token') ? generate_token('form') : '';
        if (($_GET['view'] ?? '') === 'settings') {
            echo lwbd_settings_render($set, $vars['modulelink'], $token, $flash);
            return;
        }
        $d = lwbd_collect((int) ($_GET['p'] ?? $set['period']));
        $show = function ($key) use ($set) {
            return !in_array($key, $set['hidden'], true);
        };
        echo lwbd_render($d, $vars['modulelink'], [
            'flash' => $flash,
            'token' => $token,
            'hidden' => $set['hidden'],
            'forecast' => $show('goal') ? lwbd_forecast($d, (float) $set['goal']) : null,
            'health' => $show('health') ? lwbd_health() : null,
        ]);
    } catch (\Throwable $e) {
        logActivity('Lwb-dashboard : ' . $e->getMessage());
        echo '<div class="alert alert-danger">Le tableau de bord n\'a pas pu être chargé : ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
