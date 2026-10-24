<?php
/**
 * Lwb-dashboard — analyses : suspensions imminentes, marge réelle, fidélité des clients.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

// ------------------------------------------------------------------ fonctions pures

/** Montant mensuel d'un service selon son cycle (0 pour un paiement unique). */
function lwbd_monthly_amount($amount, $cycle)
{
    return isset(LWBD_CYCLE_MONTHS[$cycle]) ? (float) $amount / LWBD_CYCLE_MONTHS[$cycle] : 0.0;
}

/**
 * Lit une liste « libellé = montant » (une par ligne, virgule ou point décimal).
 * Les lignes vides et les commentaires (#) sont ignorés.
 *
 * @return array [libellé => montant]
 */
function lwbd_parse_costs($text)
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n|;/', (string) $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$label, $value] = array_map('trim', explode('=', $line, 2));
        $value = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], $value);
        $value = preg_replace('/[^0-9.\-]/', '', $value);
        if ($label !== '' && is_numeric($value)) {
            $out[strtolower(ltrim($label, '.'))] = (float) $value;
        }
    }
    return $out;
}

/** Extension d'un nom de domaine : « exemple.co.uk » -> « co.uk ». */
function lwbd_tld($domain)
{
    $parts = explode('.', strtolower(trim((string) $domain)), 2);
    return $parts[1] ?? '';
}

/**
 * Coût annuel d'achat d'un domaine : extension exacte (co.uk), puis dernière partie (uk),
 * puis « * » (coût par défaut). null si inconnu.
 */
function lwbd_domain_cost($domain, array $costs)
{
    $tld = lwbd_tld($domain);
    if ($tld !== '' && isset($costs[$tld])) {
        return $costs[$tld];
    }
    $last = substr(strrchr('.' . $tld, '.'), 1);
    if ($last !== '' && isset($costs[$last])) {
        return $costs[$last];
    }
    return $costs['*'] ?? null;
}

/** Date de suspension automatique d'une facture (échéance + délai WHMCS). */
function lwbd_suspend_date($due, $days)
{
    return date('Y-m-d', strtotime(substr((string) $due, 0, 10) . ' +' . (int) $days . ' days'));
}

/** Taux de perte : perdus / (actifs + perdus), en pourcentage arrondi à 0,1. */
function lwbd_churn_rate($lost, $active)
{
    $base = (int) $lost + (int) $active;
    return $base > 0 ? round($lost / $base * 100, 1) : 0.0;
}

// ------------------------------------------------------------------ revenu récurrent

function lwbd_mrr()
{
    $mrr = 0.0;
    foreach (Capsule::table('tblhosting')->where('domainstatus', 'Active')->get(['amount', 'billingcycle']) as $s) {
        $mrr += lwbd_monthly_amount($s->amount, $s->billingcycle);
    }
    foreach (Capsule::table('tblhostingaddons')->where('status', 'Active')->get(['recurring', 'billingcycle']) as $a) {
        $mrr += lwbd_monthly_amount($a->recurring, $a->billingcycle);
    }
    foreach (Capsule::table('tbldomains')->where('status', 'Active')->get(['recurringamount', 'registrationperiod']) as $dom) {
        $mrr += (float) $dom->recurringamount / (12 * max(1, (int) $dom->registrationperiod));
    }
    return round($mrr, 2);
}

/** Mémorise le revenu récurrent du jour (historique de 400 jours). */
function lwbd_mrr_snapshot()
{
    $h = lwbd_state_get('mrr_history', []);
    $h[date('Y-m-d')] = lwbd_mrr();
    ksort($h);
    lwbd_state_set('mrr_history', array_slice($h, -400, null, true));
    return $h;
}

// ------------------------------------------------------------------ suspensions imminentes

/**
 * Factures impayées dont les services seront suspendus automatiquement dans les
 * 2 prochains jours (ou aujourd'hui), avec les services concernés.
 */
function lwbd_suspensions()
{
    $cfg = Capsule::table('tblconfiguration')->whereIn('setting', ['AutoSuspension', 'AutoSuspensionDays'])->pluck('value', 'setting')->all();
    $days = (int) ($cfg['AutoSuspensionDays'] ?? 0);
    if (($cfg['AutoSuspension'] ?? '') !== 'on' || $days < 1) {
        return ['enabled' => false, 'days' => $days, 'items' => []];
    }
    $override = Capsule::schema()->hasColumn('tblhosting', 'overideautosuspend');
    $today = date('Y-m-d');
    $items = [];
    foreach (lwbd_unpaid_invoices() as $inv) {
        $balance = round((float) $inv->balance, 2);
        $late = lwbd_days_between($inv->duedate, $today);
        if ($balance <= 0 || $late < max(1, $days - 2) || $late > $days) {
            continue;
        }
        $cols = ['h.id', 'h.domain', 'p.name'];
        if ($override) {
            $cols[] = 'h.overideautosuspend';
            $cols[] = 'h.overidesuspenduntil';
        }
        $services = Capsule::table('tblinvoiceitems as it')->join('tblhosting as h', 'h.id', '=', 'it.relid')
            ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
            ->where('it.invoiceid', $inv->id)->where('it.type', 'Hosting')->where('h.domainstatus', 'Active')->get($cols)->all();
        if (!$services) {
            continue;
        }
        $suspendOn = lwbd_suspend_date($inv->duedate, $days);
        $delayedUntil = null;
        $active = [];
        foreach ($services as $s) {
            if ($override && (int) $s->overideautosuspend === 1 && lwbd_date_ok($s->overidesuspenduntil) && $s->overidesuspenduntil >= $today) {
                $delayedUntil = max((string) $delayedUntil, (string) $s->overidesuspenduntil);
            } else {
                $active[] = $s;
            }
        }
        $client = Capsule::table('tblclients')->where('id', $inv->userid)->first(['phonenumber', 'country']);
        $items[] = [
            'id' => (int) $inv->id, 'num' => $inv->invoicenum ?: $inv->id, 'userid' => (int) $inv->userid, 'client' => lwbd_client_name($inv),
            'balance' => $balance, 'late' => $late, 'suspend_on' => $suspendOn, 'in_days' => lwbd_days_between($today, $suspendOn),
            'services' => array_map(function ($s) {
                return $s->name . ($s->domain ? ' · ' . $s->domain : '');
            }, $active ?: $services),
            'service_ids' => array_map(function ($s) {
                return (int) $s->id;
            }, $services),
            'delayed_until' => $active ? null : $delayedUntil,
            'phone' => $client ? lwbd_wa_phone($client->phonenumber, $client->country) : '',
            'tel' => $client ? preg_replace('/[^0-9+]/', '', (string) $client->phonenumber) : '',
        ];
    }
    usort($items, function ($a, $b) {
        return [$a['delayed_until'] !== null, $a['in_days'], -$a['balance']] <=> [$b['delayed_until'] !== null, $b['in_days'], -$b['balance']];
    });
    return ['enabled' => true, 'days' => $days, 'items' => $items];
}

/** Accorde un délai : la suspension automatique des services de la facture est repoussée. */
function lwbd_delay_suspension($invoiceId, $days)
{
    if (!Capsule::schema()->hasColumn('tblhosting', 'overideautosuspend')) {
        return ['danger', 'Cette version de WHMCS ne permet pas de repousser la suspension.'];
    }
    $days = in_array((int) $days, [3, 7], true) ? (int) $days : 3;
    foreach (lwbd_suspensions()['items'] as $it) {
        if ($it['id'] !== (int) $invoiceId) {
            continue;
        }
        $until = date('Y-m-d', strtotime(max(date('Y-m-d'), $it['suspend_on']) . ' +' . $days . ' days'));
        Capsule::table('tblhosting')->whereIn('id', $it['service_ids'])->update(['overideautosuspend' => 1, 'overidesuspenduntil' => $until]);
        logActivity('Lwb-dashboard : suspension automatique repoussée au ' . $until . ' - Invoice ID: ' . (int) $invoiceId . ' - Service ID(s): ' . implode(', ', $it['service_ids']));
        return ['success', 'Suspension repoussée au ' . lwbd_date($until) . ' pour la facture n° ' . $it['num'] . '.'];
    }
    return ['warning', 'Cette facture n\'est plus concernée par une suspension imminente.'];
}

/** « Dernier rappel avant suspension » par WhatsApp (module lwb-whmcs-wha). */
function lwbd_wa_last_reminder($invoiceId)
{
    if (!lwbd_wha_active()) {
        return ['warning', 'Le module WhatsApp n\'est pas actif : utilisez le bouton WhatsApp (lien).'];
    }
    $res = \LwbWha\Queue::sendNotification('invoice_overdue_3', (int) $invoiceId);
    if (!empty($res['ok'])) {
        logActivity('Lwb-dashboard : dernier rappel WhatsApp envoyé - Invoice ID: ' . (int) $invoiceId);
        return ['success', 'Dernier rappel envoyé sur WhatsApp' . (!empty($res['phone']) ? ' au +' . $res['phone'] : '') . '.'];
    }
    if (($res['status'] ?? '') === 'pending') {
        return ['warning', 'Message mis en file d\'attente : il partira au prochain passage du cron.'];
    }
    return ['danger', 'WhatsApp : ' . ($res['error'] ?: 'envoi impossible')];
}

// ------------------------------------------------------------------ marge réelle

/**
 * Marge mensuelle : encaissements nets - coût d'achat des domaines vendus - frais fixes.
 *
 * @param int $months nombre de mois (le mois en cours compris)
 */
function lwbd_margin($months = 6)
{
    $set = lwbd_settings();
    $fixed = lwbd_parse_costs($set['costs_fixed']);
    $costs = lwbd_parse_costs($set['costs_domains']);
    $fixedTotal = array_sum($fixed);
    $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $series[$m] = ['month' => $m, 'revenue' => 0.0, 'domain_revenue' => 0.0, 'domain_cost' => 0.0, 'fixed' => $fixedTotal, 'margin' => 0.0];
    }
    foreach (Capsule::table('tblaccounts')->where('date', '>=', $from)
        ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS m, SUM(amountin - fees - amountout) AS net")->groupBy('m')->get() as $r) {
        if (isset($series[$r->m])) {
            $series[$r->m]['revenue'] = round((float) $r->net, 2);
        }
    }
    $unknown = [];
    $items = Capsule::table('tblinvoiceitems as it')->join('tblinvoices as i', 'i.id', '=', 'it.invoiceid')
        ->leftJoin('tbldomains as d', 'd.id', '=', 'it.relid')
        ->where('i.status', 'Paid')->where('i.datepaid', '>=', $from)
        ->whereIn('it.type', ['Domain', 'DomainRegister', 'DomainTransfer'])
        ->get(['i.datepaid', 'it.amount', 'it.description', 'd.domain', 'd.registrationperiod']);
    foreach ($items as $it) {
        $m = substr((string) $it->datepaid, 0, 7);
        if (!isset($series[$m])) {
            continue;
        }
        $domain = $it->domain ?: (preg_match('/([a-z0-9-]+(\.[a-z0-9-]+)+)/i', (string) $it->description, $mm) ? $mm[1] : '');
        $series[$m]['domain_revenue'] += (float) $it->amount;
        $cost = lwbd_domain_cost($domain, $costs);
        if ($cost === null) {
            $unknown[lwbd_tld($domain) ?: '?'] = true;
            continue;
        }
        $series[$m]['domain_cost'] += $cost * max(1, (int) $it->registrationperiod);
    }
    foreach ($series as &$s) {
        $s['domain_cost'] = round($s['domain_cost'], 2);
        $s['domain_revenue'] = round($s['domain_revenue'], 2);
        $s['margin'] = round($s['revenue'] - $s['domain_cost'] - $s['fixed'], 2);
    }
    unset($s);
    $series = array_values($series);
    $total = ['revenue' => array_sum(array_column($series, 'revenue')), 'domain_cost' => array_sum(array_column($series, 'domain_cost')),
        'domain_revenue' => array_sum(array_column($series, 'domain_revenue')), 'fixed' => array_sum(array_column($series, 'fixed'))];
    $total['margin'] = round($total['revenue'] - $total['domain_cost'] - $total['fixed'], 2);
    return [
        'configured' => (bool) ($fixed || $costs),
        'series' => $series,
        'total' => $total,
        'pct' => $total['revenue'] > 0 ? round($total['margin'] / $total['revenue'] * 100) : null,
        'fixed' => $fixed,
        'unknown_tlds' => array_keys($unknown),
        'months' => $months,
    ];
}

// ------------------------------------------------------------------ fidélité

/** Revenu récurrent gagné et perdu par mois, taux de perte et clients perdus. */
function lwbd_retention($months = 6)
{
    $today = date('Y-m-d');
    $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $d90 = date('Y-m-d', strtotime('-90 days'));
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $series[$m] = ['month' => $m, 'gained' => 0.0, 'lost' => 0.0, 'new' => 0, 'lost_count' => 0];
    }
    $start = min($from, $d90);
    $lost = [];
    foreach (Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->whereIn('h.domainstatus', ['Cancelled', 'Terminated'])->where('h.termination_date', '>=', $start)->where('h.termination_date', '<=', $today)
        ->get(['h.id', 'h.userid', 'h.domain', 'h.amount', 'h.billingcycle', 'h.termination_date', 'p.name', 'c.firstname', 'c.lastname', 'c.companyname']) as $s) {
        $lost[] = ['kind' => 'service', 'id' => (int) $s->id, 'userid' => (int) $s->userid, 'client' => lwbd_client_name($s), 'label' => $s->name . ($s->domain ? ' · ' . $s->domain : ''),
            'date' => substr((string) $s->termination_date, 0, 10), 'monthly' => round(lwbd_monthly_amount($s->amount, $s->billingcycle), 2)];
    }
    foreach (Capsule::table('tbldomains as d')->join('tblclients as c', 'c.id', '=', 'd.userid')
        ->whereIn('d.status', ['Expired', 'Cancelled', 'Transferred Away'])->where('d.expirydate', '>=', $start)->where('d.expirydate', '<=', $today)
        ->get(['d.id', 'd.userid', 'd.domain', 'd.recurringamount', 'd.registrationperiod', 'd.expirydate', 'c.firstname', 'c.lastname', 'c.companyname']) as $x) {
        $lost[] = ['kind' => 'domain', 'id' => (int) $x->id, 'userid' => (int) $x->userid, 'client' => lwbd_client_name($x), 'label' => $x->domain,
            'date' => substr((string) $x->expirydate, 0, 10), 'monthly' => round((float) $x->recurringamount / (12 * max(1, (int) $x->registrationperiod)), 2)];
    }
    foreach ($lost as $l) {
        $m = substr($l['date'], 0, 7);
        if (isset($series[$m])) {
            $series[$m]['lost'] += $l['monthly'];
            $series[$m]['lost_count']++;
        }
    }
    foreach (Capsule::table('tblhosting')->where('regdate', '>=', $from)->whereIn('domainstatus', ['Active', 'Suspended', 'Cancelled', 'Terminated'])
        ->get(['regdate', 'amount', 'billingcycle']) as $s) {
        $m = substr((string) $s->regdate, 0, 7);
        if (isset($series[$m])) {
            $series[$m]['gained'] += lwbd_monthly_amount($s->amount, $s->billingcycle);
            $series[$m]['new']++;
        }
    }
    foreach (Capsule::table('tbldomains')->where('registrationdate', '>=', $from)->whereNotIn('status', ['Pending', 'Fraud', 'Cancelled'])
        ->get(['registrationdate', 'recurringamount', 'registrationperiod']) as $x) {
        $m = substr((string) $x->registrationdate, 0, 7);
        if (isset($series[$m])) {
            $series[$m]['gained'] += (float) $x->recurringamount / (12 * max(1, (int) $x->registrationperiod));
            $series[$m]['new']++;
        }
    }
    foreach ($series as &$s) {
        $s['gained'] = round($s['gained'], 2);
        $s['lost'] = round($s['lost'], 2);
        $s['net'] = round($s['gained'] - $s['lost'], 2);
    }
    unset($s);

    $lost90 = array_values(array_filter($lost, function ($l) use ($d90) {
        return $l['date'] >= $d90;
    }));
    usort($lost90, function ($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
    $activeServices = Capsule::table('tblhosting')->where('domainstatus', 'Active')->count() + Capsule::table('tbldomains')->where('status', 'Active')->count();
    // Clients perdus : plus aucun service ni domaine actif après une perte sur 90 jours.
    $lostClients = [];
    foreach (array_unique(array_column($lost90, 'userid')) as $uid) {
        $still = Capsule::table('tblhosting')->where('userid', $uid)->whereIn('domainstatus', ['Active', 'Suspended'])->exists()
            || Capsule::table('tbldomains')->where('userid', $uid)->where('status', 'Active')->exists();
        if (!$still) {
            $lostClients[] = $uid;
        }
    }
    $history = lwbd_state_get('mrr_history', []);
    $mrr = lwbd_mrr();
    $ref = null;
    foreach ($history as $day => $v) {
        if ($day <= date('Y-m-d', strtotime('-30 days'))) {
            $ref = ['date' => $day, 'value' => (float) $v];
        }
    }
    return [
        'series' => array_values($series),
        'mrr' => $mrr,
        'mrr_ref' => $ref,
        'mrr_history_days' => count($history),
        'lost90' => $lost90,
        'lost90_mrr' => round(array_sum(array_column($lost90, 'monthly')), 2),
        'churn90' => lwbd_churn_rate(count($lost90), $activeServices),
        'lost_clients' => count($lostClients),
        'active' => $activeServices,
    ];
}
