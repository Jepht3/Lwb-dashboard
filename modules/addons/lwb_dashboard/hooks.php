<?php
/**
 * Lwb-dashboard — thème de l'administration, notifications et widget « en un coup d'œil ».
 */

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

require_once __DIR__ . '/lib/brand.php';
require_once __DIR__ . '/lib/data.php';
require_once __DIR__ . '/lib/health.php';
require_once __DIR__ . '/lib/monitor.php';
require_once __DIR__ . '/lib/security.php';
require_once __DIR__ . '/lib/insights.php';
require_once __DIR__ . '/lib/digest.php';
require_once __DIR__ . '/lib/search.php';
require_once __DIR__ . '/lib/actions.php';
require_once __DIR__ . '/lib/view.php';
require_once __DIR__ . '/lib/todo.php';
require_once __DIR__ . '/lib/notify.php';
require_once __DIR__ . '/lib/lang.php';

use WHMCS\Database\Capsule;

/** L'administrateur connecté a-t-il accès à l'extension (rôles cochés dans WHMCS) ? */
function lwbd_admin_allowed()
{
    $adminId = (int) ($_SESSION['adminid'] ?? 0);
    if (!$adminId) {
        return false;
    }
    $role = (int) Capsule::table('tbladmins')->where('id', $adminId)->value('roleid');
    return in_array($role, array_map('intval', explode(',', lwbd_settings()['access'])), true);
}

/** Nombre d'éléments « À faire », gardé 60 s en session pour ne pas recalculer à chaque page. */
function lwbd_todo_count_cached()
{
    $c = $_SESSION['lwbd_todo_cache'] ?? null;
    if (!$c || $c['t'] < time() - 60) {
        $c = ['t' => time(), 'n' => lwbd_todo_items(true)];
        $_SESSION['lwbd_todo_cache'] = $c;
    }
    return (int) $c['n'];
}

/**
 * Données pour le navigateur (JSON) : notifications et message WhatsApp d'une facture.
 * addonmodules.php?module=lwb_dashboard&lwbd_ajax=notif | search&q=… | wa&invoice=ID
 */
add_hook('AdminAreaPage', 0, function ($vars) {
    if (($vars['filename'] ?? '') !== 'addonmodules' || ($_GET['module'] ?? '') !== 'lwb_dashboard' || !isset($_GET['lwbd_ajax'])) {
        return [];
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        if (!lwbd_admin_allowed()) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }
        if ($_GET['lwbd_ajax'] === 'notif') {
            echo json_encode(lwbd_notifications(), JSON_INVALID_UTF8_SUBSTITUTE);
        } elseif ($_GET['lwbd_ajax'] === 'search') {
            echo json_encode(['q' => (string) ($_GET['q'] ?? ''), 'results' => lwbd_search((string) ($_GET['q'] ?? ''))], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
        } elseif ($_GET['lwbd_ajax'] === 'wa') {
            $wa = lwbd_wa_invoice((int) ($_GET['invoice'] ?? 0));
            echo json_encode($wa ? ['phone' => $wa['phone'], 'url' => $wa['url'], 'link' => $wa['link'], 'client' => $wa['client']] : ['error' => 'not_found'], JSON_INVALID_UTF8_SUBSTITUTE);
        } else {
            echo json_encode(['error' => 'unknown']);
        }
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'server']);
        logActivity('Lwb-dashboard (ajax) : ' . $e->getMessage());
    }
    exit;
});

/**
 * Tableau de bord comme page d'accueil de l'admin : redirige index.php sans
 * paramètre (c'est là qu'arrive l'admin après connexion), seulement si
 * l'administrateur a accès au module.
 */
add_hook('AdminAreaPage', 1, function ($vars) {
    try {
        if (($vars['filename'] ?? '') !== 'index' || !empty($_GET) || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET'
            || lwbd_settings()['homepage'] !== 'on') {
            return [];
        }
        $adminId = (int) ($_SESSION['adminid'] ?? 0);
        $role = (int) Capsule::table('tbladmins')->where('id', $adminId)->value('roleid');
        $allowed = array_map('intval', explode(',', lwbd_settings()['access']));
        if (!$adminId || !in_array($role, $allowed, true)) {
            return [];
        }
        header('Location: addonmodules.php?module=lwb_dashboard');
        exit;
    } catch (\Throwable $e) {
        return [];
    }
});

/** Thème Lwb-admin : styles dans l'en-tête de chaque page de l'administration. */
add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    try {
        if (!lwbd_admin_theme_enabled()) {
            return '';
        }
        $base = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/addons/lwb_dashboard/assets/';
        $v = @filemtime(__DIR__ . '/assets/admin-theme.css');
        if (lwbd_settings()['admin_theme'] === 'on' && empty($_SESSION['lwbd_login_checked'])) {
            // Après une mise à jour de WHMCS, login.tpl est remplacé : on remet la ligne du thème.
            if (lwbd_brand()['login_theme'] !== 'off') {
                lwbd_login_patch(true);
            }
            // Idem pour la traduction corrigée : recréée si absente ou plus ancienne que celle de WHMCS.
            $ov = lwbd_admin_dir() . '/lang/overrides/french.php';
            if (!is_file($ov) || @filemtime($ov) < @filemtime(lwbd_admin_dir() . '/lang/french.php')) {
                lwbd_lang_install();
            }
            $_SESSION['lwbd_login_checked'] = 1;
        }
        $allowed = lwbd_admin_allowed();
        $cfg = json_encode([
            'logo' => lwbd_brand()['brand_logo'],
            'name' => lwbd_brand_name(),
            'dashboard' => 'addonmodules.php?module=lwb_dashboard',
            'addLabel' => 'Nouveau',
            'tools' => $allowed,
            'todoUrl' => 'addonmodules.php?module=lwb_dashboard&view=todo',
            'todo' => $allowed ? lwbd_todo_count_cached() : 0,
            'ajax' => 'addonmodules.php?module=lwb_dashboard&lwbd_ajax=',
            'setup' => lwbd_setup_menu(),
        ], JSON_HEX_TAG | JSON_UNESCAPED_SLASHES);
        return '<script>document.documentElement.classList.add("lwba");window.LWBA=' . $cfg . ';</script>'
            . '<link href="' . $base . 'admin-theme.css?v=' . $v . '" rel="stylesheet">'
            . lwbd_brand_style();
    } catch (\Throwable $e) {
        return '';
    }
});

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    try {
        if (!lwbd_admin_theme_enabled()) {
            return '';
        }
        $base = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/addons/lwb_dashboard/assets/';
        return '<script src="' . $base . 'admin-theme.js?v=' . @filemtime(__DIR__ . '/assets/admin-theme.js') . '"></script>'
            . '<script src="' . $base . 'admin-polish.js?v=' . @filemtime(__DIR__ . '/assets/admin-polish.js') . '"></script>'
            . '<script src="' . $base . 'admin-tools.js?v=' . @filemtime(__DIR__ . '/assets/admin-tools.js') . '"></script>';
    } catch (\Throwable $e) {
        return '';
    }
});

/** Recherche rapide (Ctrl+K) sur toutes les pages, pour les administrateurs autorisés. */
add_hook('AdminAreaFooterOutput', 2, function ($vars) {
    try {
        if (!lwbd_admin_allowed()) {
            return '';
        }
        $base = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/addons/lwb_dashboard/assets/';
        return '<script>window.LWBD_SEARCH=' . json_encode(['url' => 'addonmodules.php?module=lwb_dashboard&lwbd_ajax=search&q='], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>'
            . '<script src="' . $base . 'admin-search.js?v=' . @filemtime(__DIR__ . '/assets/admin-search.js') . '"></script>';
    } catch (\Throwable $e) {
        return '';
    }
});

/** Surveillance : alertes à l'équipe (au plus toutes les 15 minutes). */
add_hook('AfterCronJob', 50, function ($vars) {
    try {
        lwbd_alerts_run();
    } catch (\Throwable $e) {
        logActivity('Lwb-dashboard (alertes) : ' . $e->getMessage());
    }
});

/**
 * Tâche quotidienne : historique du revenu récurrent, analyse des fichiers, puis
 * résumé du matin (l'analyse passe avant pour que le résumé en tienne compte).
 */
add_hook('DailyCronJob', 50, function ($vars) {
    foreach (['lwbd_mrr_snapshot', 'lwbd_files_scan', 'lwbd_digest_send'] as $fn) {
        try {
            $fn();
        } catch (\Throwable $e) {
            logActivity('Lwb-dashboard (' . $fn . ') : ' . $e->getMessage());
        }
    }
});

if (class_exists('\WHMCS\Module\AbstractWidget')) {
    class LwbDashboardWidget extends \WHMCS\Module\AbstractWidget
    {
        protected $title = 'En un coup d\'œil';
        protected $description = 'Encaissements, impayés, renouvellements et domaines à risque.';
        protected $weight = 5;
        protected $columns = 1;
        protected $cache = true;
        protected $cacheExpiry = 300;
        protected $requiredPermission = '';

        public function getTitle()
        {
            return lwbd_brand_name() . ' en un coup d\'œil';
        }

        public function getData()
        {
            $d = lwbd_collect(6);
            $h = lwbd_health();
            return [
                'alerts' => (int) !$h['cron']['ok'] + (int) $h['registrar']['no_credit'],
                'cron_down' => !$h['cron']['ok'],
                'no_credit' => (bool) $h['registrar']['no_credit'],
                'month' => $d['month']['current'],
                'mrr' => $d['mrr'],
                'unpaid' => $d['unpaid']['total'],
                'unpaid_count' => $d['unpaid']['count'],
                'renewals' => count($d['renewals']),
                'risks' => count(array_filter($d['domain_risks'], function ($r) {
                    return $r['level'] === 'critical';
                })),
                'pending' => $d['orders']['pending'],
                'tickets' => $d['tickets']['waiting'],
            ];
        }

        public function generateOutput($data)
        {
            $cell = function ($label, $value, $alert = false) {
                return '<div style="padding:10px 12px;border:1px solid #e6e9f2;border-radius:10px;background:#fff">'
                    . '<div style="font-size:11px;font-weight:700;color:#575b78">' . lwbd_e($label) . '</div>'
                    . '<div style="font-size:18px;font-weight:800;color:' . ($alert ? '#b42318' : '#0d0d2b') . ';white-space:nowrap">' . lwbd_e($value) . '</div></div>';
            };
            $what = array_filter([!empty($data['cron_down']) ? 'le cron WHMCS ne tourne plus' : '', !empty($data['no_credit']) ?'crédit ' . lwbd_main_registrar()['name'] . ' épuisé' : '']);
            $alert = $what ? '<a href="addonmodules.php?module=lwb_dashboard" style="display:block;margin-bottom:8px;padding:9px 12px;border-radius:10px;background:#fdecec;color:#b42318;font-weight:700;text-decoration:none">⚠ Urgent : ' . lwbd_e(implode(' · ', $what)) . '</a>' : '';
            return '<div style="padding:12px;font-family:var(--a-font,inherit)">' . $alert
                . '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">'
                . $cell('Encaissé ce mois', lwbd_money($data['month']))
                . $cell('Revenu récurrent / mois', lwbd_money($data['mrr']))
                . $cell('Impayés (' . $data['unpaid_count'] . ')', lwbd_money($data['unpaid']), $data['unpaid'] > 0)
                . $cell('Renouvellements 30 j', (string) $data['renewals'])
                . $cell('Domaines en danger', (string) $data['risks'], $data['risks'] > 0)
                . $cell('Tickets à traiter', (string) $data['tickets'], $data['tickets'] > 0)
                . '</div><a href="addonmodules.php?module=lwb_dashboard" style="display:block;margin-top:10px;padding:9px;border-radius:10px;background:#030673;color:#fff;text-align:center;font-weight:700;text-decoration:none">Ouvrir le tableau de bord complet →</a></div>';
        }
    }

    // Chiffres financiers : le widget n'est proposé qu'aux rôles autorisés à voir l'extension.
    try {
        if (defined('ADMINAREA') && lwbd_admin_allowed()) {
            add_hook('AdminHomeWidgets', 1, function () {
                return new LwbDashboardWidget();
            });
        }
    } catch (\Throwable $e) {
    }
}
