<?php
/**
 * LWB Admin (Lwb-dashboard) — tableau de bord et thème d'administration pour tout WHMCS.
 *
 * Encaissements, revenu récurrent, impayés par ancienneté, renouvellements,
 * domaines à risque, commandes, paiements, tickets et meilleurs clients,
 * sur une seule page, plus un widget sur l'accueil de l'administration et le
 * thème Lwb-admin par-dessus Blend. Nom, logo, couleurs et police se règlent
 * dans la page « Réglages » ; la devise est celle de WHMCS.
 *
 * Installation : copier le dossier dans /modules/addons/ de WHMCS, puis
 * Configuration > Modules complémentaires > LWB Admin > Activer.
 * Open source (MIT), par LEWEBMAX Admin.
 */

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

require_once __DIR__ . '/lib/brand.php';
require_once __DIR__ . '/lib/data.php';
require_once __DIR__ . '/lib/health.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/todo.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/lang.php';

function lwb_dashboard_config()
{
    return [
        'name' => 'LWB Admin',
        'description' => 'Tableau de bord et thème moderne de l\'administration : encaissements, revenu récurrent, impayés, renouvellements, domaines à risque, file « À faire » et notifications. Marque, couleurs et police réglables.',
        'version' => '2.1',
        'author' => 'LEWEBMAX Admin',
        // Les réglages se font sur la page « Réglages » du tableau de bord, dans le même design.
        'fields' => [],
    ];
}

function lwb_dashboard_upgrade($vars)
{
    lwbd_snooze_table();
    lwbd_reminders_table();
    // Traduction française corrigée de l'administration (« ticket », « Support »…).
    lwbd_lang_install();
    // 2.0 : la ligne de login.tpl charge la feuille de la marque au lieu de Google Fonts en dur.
    lwbd_login_css_write();
    if (lwbd_settings()['admin_theme'] === 'on') {
        lwbd_login_patch(false);
        lwbd_login_patch(lwbd_brand()['login_theme'] !== 'off');
    }
}

function lwb_dashboard_activate()
{
    lwbd_snooze_table();
    lwbd_reminders_table();
    lwbd_login_css_write();
    lwbd_lang_install();
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
        if (($_GET['view'] ?? '') === 'todo') {
            echo lwbd_todo_render(lwbd_todo_items(), $vars['modulelink'], $token, $flash);
            return;
        }
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
            'todo_count' => lwbd_todo_items(true),
            'linktoken' => function_exists('generate_token') ? generate_token('link') : '',
        ]);
    } catch (\Throwable $e) {
        logActivity('Lwb-dashboard : ' . $e->getMessage());
        echo '<div class="alert alert-danger">Le tableau de bord n\'a pas pu être chargé : ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}
