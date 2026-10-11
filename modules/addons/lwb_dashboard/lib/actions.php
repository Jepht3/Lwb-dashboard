<?php
/**
 * Lwb-dashboard — actions depuis le tableau de bord (relance d'une facture).
 *
 * Seule action qui écrit : l'envoi d'un e-mail de relance, sur demande de
 * l'administrateur, protégé par le jeton CSRF de WHMCS.
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['lwbd_action'] ?? '') !== 'remind') {
        return null;
    }
    if (function_exists('check_token')) {
        check_token('WHMCS.admin.default');
    }
    $id = (int) ($_POST['invoice'] ?? 0);
    $inv = Capsule::table('tblinvoices')->where('id', $id)->where('status', 'Unpaid')->first(['id', 'invoicenum', 'duedate']);
    if (!$inv) {
        return ['danger', 'Facture introuvable ou déjà réglée.'];
    }
    if (Capsule::schema()->hasTable('mod_lwm_reminders')
        && Capsule::table('mod_lwm_reminders')->where('invoice_id', $id)->where('last_sent', date('Y-m-d'))->exists()) {
        return ['warning', 'Une relance est déjà partie aujourd\'hui pour la facture n° ' . ($inv->invoicenum ?: $id) . '.'];
    }
    $template = lwbd_reminder_template(lwbd_days_between($inv->duedate, date('Y-m-d')));
    $result = localAPI('SendEmail', ['messagename' => $template, 'id' => $id]);
    if (($result['result'] ?? '') !== 'success') {
        return ['danger', 'Échec de l\'envoi : ' . ($result['message'] ?? 'erreur inconnue')];
    }
    if (Capsule::schema()->hasTable('mod_lwm_reminders')) {
        $row = Capsule::table('mod_lwm_reminders')->where('invoice_id', $id)->first();
        Capsule::table('mod_lwm_reminders')->updateOrInsert(['invoice_id' => $id], ['last_sent' => date('Y-m-d'), 'count' => ($row->count ?? 0) + 1]);
    }
    logActivity('Lwb-dashboard : relance manuelle envoyée (' . $template . ') - Invoice ID: ' . $id);
    return ['success', 'Relance envoyée pour la facture n° ' . ($inv->invoicenum ?: $id) . '.'];
}
