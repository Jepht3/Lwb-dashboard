<?php
/**
 * Lwb-dashboard — santé du système et prévisions (lecture seule).
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

function lwbd_minutes_since($datetime)
{
    return lwbd_date_ok($datetime) ? (int) floor((time() - strtotime($datetime)) / 60) : null;
}

function lwbd_ago($minutes)
{
    if ($minutes === null) {
        return 'jamais';
    }
    if ($minutes < 60) {
        return 'il y a ' . max(1, $minutes) . ' min';
    }
    if ($minutes < 2880) {
        return 'il y a ' . floor($minutes / 60) . ' h';
    }
    return 'il y a ' . floor($minutes / 1440) . ' j';
}

/** Raccourcit une ligne du journal d'activité pour l'affichage. */
function lwbd_log_excerpt($text, $max = 150)
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $text)));
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

/**
 * État du cron, échecs chez le registraire, erreurs récentes, résiliations,
 * services payés non activés et e-mails envoyés.
 */
function lwbd_health()
{
    $cfg = Capsule::table('tblconfiguration')
        ->whereIn('setting', ['lastCronInvocationTime', 'lastDailyCronInvocationTime'])->pluck('value', 'setting')->all();
    $cron = lwbd_minutes_since($cfg['lastCronInvocationTime'] ?? null);
    $daily = lwbd_minutes_since($cfg['lastDailyCronInvocationTime'] ?? null);

    $since = date('Y-m-d H:i:s', strtotime('-7 days'));
    $registrar = Capsule::table('tblactivitylog')->where('date', '>=', $since)
        ->where(function ($q) {
            $q->where('description', 'like', 'Domain Registration Failed%')
                ->orWhere('description', 'like', 'Domain Renewal Failed%')
                ->orWhere('description', 'like', 'Domain Transfer Failed%');
        })->orderBy('id', 'desc')->get(['date', 'description']);
    $noCredit = $registrar->first(function ($r) {
        return stripos($r->description, 'INSUFFICIENT_FUNDS') !== false || stripos($r->description, 'credit is $0') !== false;
    });

    $errors = Capsule::table('tblactivitylog')->where('date', '>=', $since)
        ->where(function ($q) {
            foreach (['%Smarty Error%', '%ÉCHEC%', '%échec%', '%Fatal%', '%Module Call Error%', '%Failed%'] as $k) {
                $q->orWhere('description', 'like', $k);
            }
        })
        ->where('description', 'not like', 'Domain Registration Failed%')
        ->where('description', 'not like', 'Domain Renewal Failed%')
        ->where('description', 'not like', 'Domain Transfer Failed%')
        ->where('description', 'not like', 'Failed Login%')
        ->orderBy('id', 'desc')->get(['date', 'description']);
    // Une même erreur répétée ne compte qu'une fois.
    $unique = [];
    foreach ($errors as $e) {
        $key = md5(preg_replace('/\d+/', '#', $e->description));
        if (!isset($unique[$key])) {
            $unique[$key] = ['date' => $e->date, 'text' => lwbd_log_excerpt($e->description), 'count' => 0];
        }
        $unique[$key]['count']++;
    }

    $paidPending = Capsule::table('tblhosting as h')->join('tblorders as o', 'o.id', '=', 'h.orderid')
        ->join('tblinvoices as i', 'i.id', '=', 'o.invoiceid')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('h.domainstatus', 'Pending')->where('i.status', 'Paid')
        ->limit(10)->get(['h.id', 'h.userid', 'h.domain', 'p.name']);

    $cancels = Capsule::table('tblcancelrequests as r')->join('tblhosting as h', 'h.id', '=', 'r.relid')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->whereIn('h.domainstatus', ['Active', 'Suspended'])
        ->orderBy('r.date', 'desc')->limit(10)
        ->get(['r.date', 'r.type', 'r.reason', 'h.id', 'h.userid', 'h.domain', 'p.name']);

    return [
        'cron' => ['minutes' => $cron, 'ok' => $cron !== null && $cron <= 20],
        'daily' => ['minutes' => $daily, 'ok' => $daily !== null && $daily <= 26 * 60],
        'registrar' => [
            'count' => $registrar->count(),
            'no_credit' => (bool) $noCredit,
            'last' => $registrar->take(4)->map(function ($r) {
                return ['date' => $r->date, 'text' => lwbd_log_excerpt($r->description)];
            })->all(),
        ],
        'errors' => array_slice(array_values($unique), 0, 5),
        'errors_count' => count($unique),
        'paid_pending' => $paidPending->all(),
        'cancels' => $cancels->all(),
        'emails_today' => Capsule::table('tblemails')->where('date', '>=', date('Y-m-d'))->count(),
    ];
}

/**
 * Objectif du mois et prévisions.
 *
 * @param array $d    résultat de lwbd_collect()
 * @param float $goal objectif d'encaissement mensuel (0 = aucun)
 */
function lwbd_forecast(array $d, $goal)
{
    $day = (int) date('j');
    $days = (int) date('t');
    $current = (float) $d['month']['current'];
    // Projection : rythme du mois en cours, plus ce qui est déjà facturé et dû d'ici la fin du mois.
    $dueThisMonth = 0.0;
    foreach (lwbd_unpaid_invoices() as $inv) {
        if ($inv->duedate >= date('Y-m-01') && $inv->duedate <= date('Y-m-t') && (float) $inv->balance > 0) {
            $dueThisMonth += (float) $inv->balance;
        }
    }
    $pace = $day > 0 ? $current / $day * $days : $current;
    $expected30 = 0.0;
    foreach ($d['renewals'] as $r) {
        if (!$r['invoice'] || $r['invoice']['status'] !== 'Paid') {
            $expected30 += $r['amount'];
        }
    }
    return [
        'goal' => (float) $goal,
        'current' => $current,
        'pct' => $goal > 0 ? min(999, round($current / $goal * 100)) : null,
        'pace' => round($pace, 2),
        'due_this_month' => round($dueThisMonth, 2),
        'expected_30' => round($expected30, 2),
        'days_left' => $days - $day,
    ];
}
