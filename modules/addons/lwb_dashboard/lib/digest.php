<?php
/**
 * Lwb-dashboard — résumé du matin envoyé à l'équipe (WhatsApp, sinon e-mail),
 * avec la tâche quotidienne de WHMCS.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

/** Adresse du tableau de bord dans l'administration. */
function lwbd_dashboard_url()
{
    return rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/' . basename(lwbd_admin_dir()) . '/addonmodules.php?module=lwb_dashboard';
}

/** Chiffres du résumé (montants bruts). */
function lwbd_digest_data()
{
    $y0 = date('Y-m-d', strtotime('-1 day'));
    $yesterday = Capsule::table('tblaccounts')->whereBetween('date', [$y0 . ' 00:00:00', $y0 . ' 23:59:59'])
        ->selectRaw('COALESCE(SUM(amountin - fees - amountout), 0) AS net, SUM(amountin > 0) AS n')->first();
    $month = (float) Capsule::table('tblaccounts')->where('date', '>=', date('Y-m-01'))->sum(Capsule::raw('amountin - fees - amountout'));
    $unpaid = 0.0;
    $late = 0.0;
    $count = 0;
    foreach (lwbd_unpaid_invoices() as $inv) {
        $b = (float) $inv->balance;
        if ($b <= 0) {
            continue;
        }
        $unpaid += $b;
        $count++;
        if (lwbd_days_between($inv->duedate, date('Y-m-d')) > 0) {
            $late += $b;
        }
    }
    $susp = array_values(array_filter(lwbd_suspensions()['items'], function ($s) {
        return $s['delayed_until'] === null && $s['in_days'] <= 1;
    }));
    $limit = date('Y-m-d', strtotime('+7 days'));
    $renew = Capsule::table('tblhosting')->where('domainstatus', 'Active')->whereIn('billingcycle', array_keys(LWBD_CYCLE_MONTHS))
        ->whereBetween('nextduedate', [date('Y-m-d'), $limit])->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')->first();
    $renewDom = Capsule::table('tbldomains')->where('status', 'Active')->whereBetween('nextduedate', [date('Y-m-d'), $limit])
        ->selectRaw('COUNT(*) AS n, COALESCE(SUM(recurringamount), 0) AS total')->first();
    $tq = Capsule::table('tbltickets')->whereIn('status', ['Open', 'Customer-Reply']);
    if (Capsule::schema()->hasColumn('tbltickets', 'merged_ticket_id')) {
        $tq->where('merged_ticket_id', 0);
    }
    $problems = [];
    foreach (lwbd_monitor() as $m) {
        if (in_array($m['level'], ['critical', 'serious'], true)) {
            $problems[] = $m['title'] . ' : ' . $m['text'];
        }
    }
    $h = lwbd_health();
    if (!$h['cron']['ok']) {
        array_unshift($problems, 'Cron WHMCS arrêté (dernier passage ' . lwbd_ago($h['cron']['minutes']) . ')');
    }
    if ($h['registrar']['no_credit']) {
        $problems[] = 'Crédit ' . lwbd_main_registrar()['name'] . ' épuisé';
    }
    $sec = [];
    try {
        $s = lwbd_security();
        if ($n = count($s['new_files']) + count($s['changed_files'])) {
            $sec[] = $n . ' fichier(s) PHP ajouté(s) ou modifié(s) dans WHMCS' . ($s['suspect'] ? ', dont ' . $s['suspect'] . ' suspect(s)' : '');
        }
        $recent = array_filter($s['logins']['new'], function ($l) {
            return strtotime($l['time']) > time() - 86400;
        });
        if ($recent) {
            $sec[] = count($recent) . ' connexion(s) admin depuis un nouveau lieu';
        }
        if ($s['logins']['failed_day']) {
            $sec[] = $s['logins']['failed_day'] . ' échec(s) de connexion admin en 24 h';
        }
    } catch (\Throwable $e) {
    }
    return [
        'brand' => lwbd_brand_name(),
        'date' => date('d/m/Y'),
        'month_label' => lwbd_month_label(date('Y-m'), true),
        'yesterday' => (float) $yesterday->net,
        'yesterday_count' => (int) $yesterday->n,
        'month' => $month,
        'goal' => (float) lwbd_settings()['goal'],
        'unpaid' => $unpaid,
        'unpaid_count' => $count,
        'late' => $late,
        'suspensions' => array_map(function ($s) {
            return ['client' => $s['client'], 'balance' => $s['balance'], 'date' => $s['suspend_on']];
        }, $susp),
        'renewals' => (int) $renew->n + (int) $renewDom->n,
        'renewals_amount' => (float) $renew->total + (float) $renewDom->total,
        'tickets' => $tq->count(),
        'orders' => Capsule::table('tblorders')->where('status', 'Pending')->count(),
        'problems' => $problems,
        'security' => $sec,
        'url' => lwbd_dashboard_url(),
    ];
}

/**
 * Texte du résumé (WhatsApp : *gras*, emojis). Fonction pure.
 *
 * @param callable|null $money formatage des montants
 */
function lwbd_digest_text(array $d, callable $money = null)
{
    $money = $money ?: function ($v) {
        return str_replace(["\u{202F}", "\u{00A0}"], ' ', lwbd_money($v));
    };
    $l = [];
    $l[] = '☀️ *Bonjour ! Résumé ' . $d['brand'] . ' du ' . $d['date'] . '*';
    $l[] = '';
    $l[] = '💰 Encaissé hier : *' . $money($d['yesterday']) . '*' . ($d['yesterday_count'] ? ' (' . $d['yesterday_count'] . ' paiement' . ($d['yesterday_count'] > 1 ? 's' : '') . ')' : '');
    $goal = $d['goal'] > 0 ? ' sur ' . $money($d['goal']) . ' (' . round($d['month'] / $d['goal'] * 100) . ' %)' : '';
    $l[] = '📅 ' . ucfirst($d['month_label']) . ' : ' . $money($d['month']) . $goal;
    $l[] = '🧾 Impayés : ' . $money($d['unpaid']) . ' · ' . $d['unpaid_count'] . ' facture' . ($d['unpaid_count'] > 1 ? 's' : '') . ($d['late'] > 0 ? ' (dont ' . $money($d['late']) . ' en retard)' : '');
    if ($d['suspensions']) {
        $l[] = '⛔ *Suspensions aujourd\'hui ou demain : ' . count($d['suspensions']) . '*';
        foreach (array_slice($d['suspensions'], 0, 4) as $s) {
            $l[] = '   • ' . $s['client'] . ' · ' . $money($s['balance']) . ' · le ' . date('d/m', strtotime($s['date']));
        }
    }
    if ($d['renewals']) {
        $l[] = '🔁 Renouvellements sous 7 jours : ' . $d['renewals'] . ' (' . $money($d['renewals_amount']) . ')';
    }
    $l[] = '🎫 Tickets à traiter : ' . $d['tickets'] . ' · 🛒 Commandes à valider : ' . $d['orders'];
    $l[] = '';
    if ($d['problems']) {
        $l[] = '⚠️ *À vérifier*';
        foreach (array_slice($d['problems'], 0, 5) as $p) {
            $l[] = '   • ' . $p;
        }
    } else {
        $l[] = '✅ Système : tout fonctionne (cron, sauvegarde, e-mails, WhatsApp, SSL, disque).';
    }
    if ($d['security']) {
        $l[] = '🔐 *Sécurité* : ' . implode(' · ', $d['security']);
    }
    $l[] = '';
    $l[] = '👉 ' . $d['url'];
    return implode("\n", $l);
}

/**
 * Envoie le résumé (une fois par jour, sauf $force). Retourne le canal utilisé ou null.
 */
function lwbd_digest_send($force = false)
{
    if (!$force && (lwbd_settings()['digest'] !== 'on' || lwbd_state_get('digest_last') === date('Y-m-d'))) {
        return null;
    }
    $d = lwbd_digest_data();
    $channel = lwbd_team_notify('digest:' . date('Ymd') . ($force ? ':' . time() : ''), $d['brand'] . ' : résumé du ' . $d['date'], lwbd_digest_text($d));
    if (!$force) {
        lwbd_state_set('digest_last', date('Y-m-d'));
    }
    logActivity('Lwb-dashboard : résumé du matin envoyé (' . $channel . ')');
    return $channel;
}
