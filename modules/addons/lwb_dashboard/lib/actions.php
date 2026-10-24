<?php
/**
 * Lwb-dashboard — actions depuis le tableau de bord (relance d'une facture).
 *
 * Toutes les actions écrivent uniquement sur demande de l'administrateur, avec le
 * jeton CSRF de WHMCS : relance d'une facture, réglages, et depuis la file
 * « À faire » : accepter une commande, activer un service, prévenir le client
 * d'un domaine en danger, reporter un élément.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

/** Modèle de relance adapté au retard (mêmes modèles que les relances automatiques). */
function lwbd_reminder_template($late)
{
    if ($late <= 0) {
        return 'Invoice Payment Reminder';
    }
    return $late < 7 ? 'First Invoice Overdue Notice' : 'Second Invoice Overdue Notice';
}

/**
 * Traite le formulaire « Relancer ». Retourne [type, message] ou null.
 */
function lwbd_handle_actions()
{
    $action = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? ($_POST['lwbd_action'] ?? '') : '';
    if (!in_array($action, ['remind', 'settings', 'accept_order', 'activate_service', 'snooze', 'domain_notice', 'delay_suspension', 'wa_last', 'files_ack', 'digest_test'], true)) {
        return null;
    }
    if (function_exists('check_token')) {
        check_token('WHMCS.admin.default');
    }
    if ($action === 'settings') {
        return lwbd_save_settings($_POST);
    }
    if ($action === 'delay_suspension') {
        return lwbd_delay_suspension((int) ($_POST['invoice'] ?? 0), (int) ($_POST['days'] ?? 3));
    }
    if ($action === 'wa_last') {
        return lwbd_wa_last_reminder((int) ($_POST['invoice'] ?? 0));
    }
    if ($action === 'files_ack') {
        lwbd_files_ack();
        logActivity('Lwb-dashboard : changements de fichiers vérifiés et acquittés');
        return ['success', 'Liste des fichiers vidée. Les prochains changements seront signalés.'];
    }
    if ($action === 'digest_test') {
        $channel = lwbd_digest_send(true);
        return $channel === 'whatsapp' ? ['success', 'Résumé mis en file : il arrive sur WhatsApp dans quelques minutes (prochain passage du cron).']
            : ($channel === 'email' ? ['success', 'WhatsApp indisponible : résumé envoyé par e-mail aux administrateurs.'] : ['danger', 'Résumé non envoyé : aucun numéro d\'équipe WhatsApp ni e-mail administrateur disponible.']);
    }
    if ($action !== 'remind') {
        return lwbd_todo_action($action, $_POST);
    }
    $id = (int) ($_POST['invoice'] ?? 0);
    $inv = Capsule::table('tblinvoices')->where('id', $id)->where('status', 'Unpaid')->first(['id', 'invoicenum', 'duedate']);
    if (!$inv) {
        return ['danger', 'Facture introuvable ou déjà réglée.'];
    }
    $reminders = lwbd_reminders_table();
    if (Capsule::table($reminders)->where('invoice_id', $id)->where('last_sent', date('Y-m-d'))->exists()) {
        return ['warning', 'Une relance est déjà partie aujourd\'hui pour la facture n° ' . ($inv->invoicenum ?: $id) . '.'];
    }
    $template = lwbd_reminder_template(lwbd_days_between($inv->duedate, date('Y-m-d')));
    $result = localAPI('SendEmail', ['messagename' => $template, 'id' => $id]);
    if (($result['result'] ?? '') !== 'success') {
        return ['danger', 'Échec de l\'envoi : ' . ($result['message'] ?? 'erreur inconnue')];
    }
    $row = Capsule::table($reminders)->where('invoice_id', $id)->first();
    Capsule::table($reminders)->updateOrInsert(['invoice_id' => $id], ['last_sent' => date('Y-m-d'), 'count' => ($row->count ?? 0) + 1]);
    logActivity('Lwb-dashboard : relance manuelle envoyée (' . $template . ') - Invoice ID: ' . $id);
    return ['success', 'Relance envoyée pour la facture n° ' . ($inv->invoicenum ?: $id) . '.'];
}

/** Enregistre la page « Réglages » : objectif, période, page d'accueil, sections affichées. */
function lwbd_save_settings(array $post)
{
    $goal = str_replace(',', '.', preg_replace('/[^0-9.,]/', '', (string) ($post['goal'] ?? '')));
    if ($goal !== '' && (!is_numeric($goal) || (float) $goal < 0)) {
        return ['danger', 'L\'objectif doit être un montant positif, par exemple 500.'];
    }
    $before = lwbd_brand(true);
    if ($err = lwbd_brand_save($post)) {
        return ['danger', $err];
    }
    $after = lwbd_brand();
    $period = (int) ($post['period'] ?? 12);
    if (!in_array($period, [6, 12, 24], true)) {
        $period = 12;
    }
    $shown = array_intersect(array_keys(lwbd_sections()), (array) ($post['show'] ?? []));
    $hidden = array_values(array_diff(array_keys(lwbd_sections()), $shown));
    lwbd_save_setting('goal', $goal === '' ? '' : (string) round((float) $goal, 2));
    lwbd_save_setting('period', (string) $period);
    lwbd_save_setting('homepage', !empty($post['homepage']) ? 'on' : '');
    lwbd_save_setting('hidden', implode(',', $hidden));
    lwbd_save_setting('alerts', !empty($post['alerts']) ? 'on' : '');
    lwbd_save_setting('digest', !empty($post['digest']) ? 'on' : '');
    $dir = trim((string) ($post['backup_dir'] ?? ''));
    if ($dir !== '' && ($dir[0] !== '/' || strpos($dir, '..') !== false)) {
        return ['danger', 'Le dossier des sauvegardes doit être un chemin absolu, par exemple /home/compte/whmcs_backups.'];
    }
    lwbd_save_setting('backup_dir', rtrim($dir, '/'));
    lwbd_save_setting('costs_fixed', mb_substr(trim((string) ($post['costs_fixed'] ?? '')), 0, 4000));
    lwbd_save_setting('costs_domains', mb_substr(trim((string) ($post['costs_domains'] ?? '')), 0, 4000));
    $theme = !empty($post['admin_theme']) ? 'on' : '';
    $themeChanged = $theme !== lwbd_settings()['admin_theme'];
    if ($themeChanged) {
        lwbd_save_setting('admin_theme', $theme);
        logActivity('Lwb-dashboard : thème moderne de l\'administration ' . ($theme === 'on' ? 'activé' : 'désactivé'));
    }
    // Page de connexion : feuille de la marque régénérée, ligne de login.tpl remise à jour si besoin.
    lwbd_login_css_write();
    if ($themeChanged || $before !== $after) {
        lwbd_login_patch(false);
        lwbd_login_patch($theme === 'on' && $after['login_theme'] !== 'off');
    }
    logActivity('Lwb-dashboard : réglages enregistrés');
    return ['success', 'Réglages enregistrés.'];
}

/** Actions de la file « À faire ». Retourne [type, message]. */
function lwbd_todo_action($action, array $post)
{
    if ($action === 'snooze') {
        $item = substr(preg_replace('/[^a-z0-9:._-]/i', '', (string) ($post['item'] ?? '')), 0, 64);
        $days = in_array((int) ($post['days'] ?? 1), [1, 7], true) ? (int) $post['days'] : 1;
        if ($item === '') {
            return ['danger', 'Élément inconnu.'];
        }
        lwbd_snooze_table();
        Capsule::table(LWBD_SNOOZE_TABLE)->updateOrInsert(['item' => $item], ['until' => date('Y-m-d H:i:s', strtotime('+' . $days . ' days'))]);
        return ['success', $days === 1 ? 'Reporté à demain.' : 'Reporté de 7 jours.'];
    }
    if ($action === 'accept_order') {
        $id = (int) ($post['order'] ?? 0);
        $order = Capsule::table('tblorders')->where('id', $id)->where('status', 'Pending')->first(['id', 'ordernum']);
        if (!$order) {
            return ['warning', 'Cette commande n\'est plus en attente.'];
        }
        $r = localAPI('AcceptOrder', ['orderid' => $id, 'autosetup' => true, 'sendemail' => true]);
        if (($r['result'] ?? '') !== 'success') {
            return ['danger', 'La commande n° ' . $order->ordernum . ' n\'a pas pu être acceptée : ' . ($r['message'] ?? 'erreur inconnue')];
        }
        logActivity('Lwb-dashboard : commande acceptée depuis « À faire » - Order ID: ' . $id);
        return ['success', 'Commande n° ' . $order->ordernum . ' acceptée, services en cours d\'activation.'];
    }
    if ($action === 'activate_service') {
        $id = (int) ($post['service'] ?? 0);
        $svc = Capsule::table('tblhosting')->where('id', $id)->where('domainstatus', 'Pending')->first(['id', 'domain']);
        if (!$svc) {
            return ['warning', 'Ce service n\'est plus en attente.'];
        }
        $r = localAPI('ModuleCreate', ['serviceid' => $id]);
        if (($r['result'] ?? '') !== 'success') {
            return ['danger', 'Activation impossible : ' . ($r['message'] ?? 'erreur inconnue')];
        }
        logActivity('Lwb-dashboard : service activé depuis « À faire » - Service ID: ' . $id);
        return ['success', 'Service ' . ($svc->domain ?: '#' . $id) . ' activé.'];
    }
    if ($action === 'domain_notice') {
        $id = (int) ($post['domain'] ?? 0);
        $dom = Capsule::table('tbldomains')->where('id', $id)->first(['id', 'domain']);
        if (!$dom) {
            return ['danger', 'Domaine introuvable.'];
        }
        $r = localAPI('SendEmail', ['messagename' => 'Upcoming Domain Renewal Notice', 'id' => $id]);
        if (($r['result'] ?? '') !== 'success') {
            return ['danger', 'Échec de l\'envoi : ' . ($r['message'] ?? 'erreur inconnue')];
        }
        logActivity('Lwb-dashboard : avis de renouvellement envoyé depuis « À faire » - Domain ID: ' . $id);
        return ['success', 'Avis de renouvellement envoyé pour ' . $dom->domain . '.'];
    }
    return null;
}
