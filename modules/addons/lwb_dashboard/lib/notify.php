<?php
/**
 * Lwb-dashboard — centre de notifications (cloche de la barre du haut).
 *
 * Événements des 7 derniers jours, du plus récent au plus ancien. Le « lu / non lu »
 * est gardé dans le navigateur de chaque administrateur : rien n'est écrit en base.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

function lwbd_notifications($limit = 40)
{
    $since = date('Y-m-d H:i:s', strtotime('-7 days'));
    $ev = [];
    $push = function ($time, $type, $icon, $title, $text, $href, $level = 'info') use (&$ev) {
        $ev[] = ['t' => strtotime((string) $time) ?: time(), 'type' => $type, 'icon' => $icon, 'title' => $title, 'text' => $text, 'href' => $href, 'level' => $level];
    };

    foreach (Capsule::table('tblorders as o')->join('tblclients as c', 'c.id', '=', 'o.userid')->where('o.date', '>=', $since)
        ->orderBy('o.date', 'desc')->limit(15)->get(['o.id', 'o.ordernum', 'o.date', 'o.amount', 'o.status', 'c.firstname', 'c.lastname', 'c.companyname']) as $o) {
        $push($o->date, 'order', 'fa-shopping-cart', 'Nouvelle commande · ' . lwbd_client_name($o), 'N° ' . $o->ordernum . ' · ' . lwbd_money($o->amount) . ($o->status === 'Pending' ? ' · à valider' : ''), 'orders.php?action=view&id=' . $o->id, $o->status === 'Pending' ? 'warning' : 'info');
    }
    $names = lwbd_gateway_names();
    foreach (Capsule::table('tblaccounts as a')->leftJoin('tblclients as c', 'c.id', '=', 'a.userid')->where('a.date', '>=', $since)->where('a.amountin', '>', 0)
        ->orderBy('a.date', 'desc')->limit(15)->get(['a.date', 'a.amountin', 'a.gateway', 'a.invoiceid', 'a.userid', 'c.firstname', 'c.lastname', 'c.companyname']) as $p) {
        $push($p->date, 'payment', 'fa-money-bill-wave', 'Paiement reçu · ' . lwbd_money($p->amountin), lwbd_client_name($p) . ' · ' . ($names[$p->gateway] ?? $p->gateway), $p->invoiceid ? 'invoices.php?action=edit&id=' . $p->invoiceid : 'transactions.php', 'good');
    }
    $tq = Capsule::table('tbltickets as t')->leftJoin('tblclients as c', 'c.id', '=', 't.userid')->where('t.lastreply', '>=', $since)->whereIn('t.status', ['Open', 'Customer-Reply']);
    foreach ($tq->orderBy('t.lastreply', 'desc')->limit(15)->get(['t.id', 't.tid', 't.title', 't.status', 't.lastreply', 't.userid', 't.name', 'c.firstname', 'c.lastname', 'c.companyname']) as $t) {
        $push($t->lastreply, 'ticket', 'fa-comment-dots', ($t->status === 'Open' ? 'Nouveau ticket' : 'Réponse du client') . ' · #' . $t->tid, $t->title . ' · ' . ($t->userid ? lwbd_client_name($t) : ($t->name ?: 'Visiteur')), 'supporttickets.php?action=view&id=' . $t->id, 'warning');
    }
    foreach (Capsule::table('tblactivitylog')->where('date', '>=', $since)
        ->where(function ($q) {
            $q->where('description', 'like', 'Domain Registration Failed%')->orWhere('description', 'like', 'Domain Renewal Failed%')
                ->orWhere('description', 'like', 'Domain Transfer Failed%')->orWhere('description', 'like', '%Module Call Error%')
                ->orWhere('description', 'like', '%ÉCHEC%');
        })->orderBy('id', 'desc')->limit(10)->get(['date', 'description']) as $l) {
        $isDomain = stripos($l->description, 'Domain') === 0;
        $push($l->date, 'error', 'fa-exclamation-triangle', $isDomain ? 'Échec chez le registraire' : 'Erreur d\'un module', lwbd_log_excerpt($l->description, 130), 'systemactivitylog.php', 'critical');
    }
    foreach (Capsule::table('tblcancelrequests as r')->join('tblhosting as h', 'h.id', '=', 'r.relid')->where('r.date', '>=', $since)
        ->orderBy('r.date', 'desc')->limit(5)->get(['r.date', 'h.domain']) as $c) {
        $push($c->date, 'cancel', 'fa-ban', 'Demande de résiliation', (string) $c->domain, 'cancelrequests.php', 'warning');
    }
    usort($ev, function ($a, $b) {
        return $b['t'] <=> $a['t'];
    });
    $ev = array_slice($ev, 0, $limit);

    // Alertes permanentes (toujours en tête tant qu'elles durent).
    $h = lwbd_health();
    $sticky = [];
    if (!$h['cron']['ok']) {
        $sticky[] = ['t' => time(), 'type' => 'alert', 'icon' => 'fa-clock', 'title' => 'Le cron WHMCS ne tourne plus', 'text' => 'Dernier passage ' . lwbd_ago($h['cron']['minutes']), 'href' => 'automationstatus.php', 'level' => 'critical', 'sticky' => true];
    }
    if ($h['registrar']['no_credit']) {
        $sticky[] = ['t' => time(), 'type' => 'alert', 'icon' => 'fa-wallet', 'title' => 'Crédit Porkbun épuisé', 'text' => 'Les enregistrements et renouvellements échouent.', 'href' => 'https://porkbun.com/account/credit', 'level' => 'critical', 'sticky' => true];
    }
    return ['now' => time(), 'sticky' => $sticky, 'events' => $ev, 'todo' => lwbd_todo_items(true)];
}
