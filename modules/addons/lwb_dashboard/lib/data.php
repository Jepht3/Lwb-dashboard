<?php
/**
 * Lwb-dashboard — collecte des chiffres (lecture seule).
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

const LWBD_CYCLE_MONTHS = [
    'Monthly' => 1, 'Quarterly' => 3, 'Semi-Annually' => 6,
    'Annually' => 12, 'Biennially' => 24, 'Triennially' => 36,
];

function lwbd_date_ok($date)
{
    return $date && strpos((string) $date, '0000-00-00') !== 0;
}

function lwbd_days_between($from, $to)
{
    return (int) floor((strtotime(substr($to, 0, 10)) - strtotime(substr($from, 0, 10))) / 86400);
}

/** Noms lisibles des moyens de paiement (y compris les anciens modules désinstallés). */
function lwbd_gateway_names()
{
    $names = Capsule::table('tblpaymentgateways')->where('setting', 'name')->pluck('value', 'gateway')->all();
    return $names + ['flexpaie' => 'FlexPay (ancien module)', 'banktransfer' => 'Virement bancaire', 'mailin' => 'Paiement manuel'];
}

/** Solde restant de chaque facture impayée : [id => balance]. */
function lwbd_unpaid_invoices()
{
    return Capsule::table('tblinvoices as i')
        ->join('tblclients as c', 'c.id', '=', 'i.userid')
        ->where('i.status', 'Unpaid')
        ->select('i.id', 'i.invoicenum', 'i.userid', 'i.duedate', 'i.total', 'c.firstname', 'c.lastname', 'c.companyname')
        ->selectRaw('i.total - COALESCE((SELECT SUM(a.amountin - a.amountout) FROM tblaccounts a WHERE a.invoiceid = i.id), 0) AS balance')
        ->get();
}

function lwbd_client_name($row)
{
    $name = trim(($row->firstname ?? '') . ' ' . ($row->lastname ?? ''));
    return !empty($row->companyname) ? $row->companyname : ($name !== '' ? $name : '#' . ($row->userid ?? ''));
}

/** Statut de la facture qui couvre un service ou un domaine pour une échéance donnée. */
function lwbd_cover_status(array $types, $relid, $due)
{
    $inv = Capsule::table('tblinvoiceitems as it')
        ->join('tblinvoices as i', 'i.id', '=', 'it.invoiceid')
        ->whereIn('it.type', $types)->where('it.relid', $relid)
        ->where('it.duedate', $due)
        ->orderBy('i.id', 'desc')
        ->first(['i.id', 'i.status']);
    return $inv ? ['id' => (int) $inv->id, 'status' => $inv->status] : null;
}

/**
 * Tous les chiffres du tableau de bord.
 *
 * @param int $months période des graphiques (6, 12 ou 24 mois)
 */
function lwbd_collect($months = 12)
{
    $months = in_array((int) $months, [6, 12, 24], true) ? (int) $months : 12;
    $today = date('Y-m-d');
    $from = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $d = ['months' => $months, 'today' => $today, 'from' => $from];

    // Encaissements par mois ------------------------------------------
    $rows = Capsule::table('tblaccounts')->where('date', '>=', $from)
        ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS m, SUM(amountin - fees - amountout) AS net, SUM(amountin > 0) AS n")
        ->groupBy('m')->pluck('net', 'm')->all();
    $counts = Capsule::table('tblaccounts')->where('date', '>=', $from)->where('amountin', '>', 0)
        ->selectRaw("DATE_FORMAT(date, '%Y-%m') AS m, COUNT(*) AS n")->groupBy('m')->pluck('n', 'm')->all();
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $m = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $series[] = ['month' => $m, 'net' => round((float) ($rows[$m] ?? 0), 2), 'count' => (int) ($counts[$m] ?? 0)];
    }
    $d['revenue'] = $series;
    $d['revenue_total'] = array_sum(array_column($series, 'net'));
    $thisMonth = end($series)['net'];
    $lastMonth = count($series) > 1 ? $series[count($series) - 2]['net'] : 0;
    // Même période le mois dernier (du 1er au même jour), pour une comparaison honnête.
    $dayLimit = date('Y-m-d', strtotime(date('Y-m-01') . ' -1 month +' . (date('j') - 1) . ' days'));
    $lastMonthToDate = (float) Capsule::table('tblaccounts')
        ->whereBetween('date', [date('Y-m-01', strtotime('first day of last month')), $dayLimit . ' 23:59:59'])
        ->sum(Capsule::raw('amountin - fees - amountout'));
    $d['month'] = ['current' => $thisMonth, 'last' => $lastMonth, 'last_to_date' => round($lastMonthToDate, 2)];

    // Revenu récurrent mensuel ----------------------------------------
    $mrr = 0.0;
    foreach (Capsule::table('tblhosting')->where('domainstatus', 'Active')->get(['amount', 'billingcycle']) as $s) {
        if (isset(LWBD_CYCLE_MONTHS[$s->billingcycle])) {
            $mrr += (float) $s->amount / LWBD_CYCLE_MONTHS[$s->billingcycle];
        }
    }
    foreach (Capsule::table('tblhostingaddons')->where('status', 'Active')->get(['recurring', 'billingcycle']) as $a) {
        if (isset(LWBD_CYCLE_MONTHS[$a->billingcycle])) {
            $mrr += (float) $a->recurring / LWBD_CYCLE_MONTHS[$a->billingcycle];
        }
    }
    foreach (Capsule::table('tbldomains')->where('status', 'Active')->get(['recurringamount', 'registrationperiod']) as $dom) {
        $mrr += (float) $dom->recurringamount / (12 * max(1, (int) $dom->registrationperiod));
    }
    $d['mrr'] = round($mrr, 2);

    // Impayés par ancienneté ------------------------------------------
    $buckets = [
        'upcoming' => ['label' => 'Pas encore échues', 'amount' => 0, 'count' => 0],
        'd30' => ['label' => 'En retard de 1 à 30 jours', 'amount' => 0, 'count' => 0],
        'd90' => ['label' => 'En retard de 31 à 90 jours', 'amount' => 0, 'count' => 0],
        'old' => ['label' => 'En retard de plus de 90 jours', 'amount' => 0, 'count' => 0],
    ];
    $unpaidList = [];
    foreach (lwbd_unpaid_invoices() as $inv) {
        $balance = round((float) $inv->balance, 2);
        if ($balance <= 0) {
            continue;
        }
        $late = lwbd_days_between($inv->duedate, $today);
        $key = $late <= 0 ? 'upcoming' : ($late <= 30 ? 'd30' : ($late <= 90 ? 'd90' : 'old'));
        $buckets[$key]['amount'] += $balance;
        $buckets[$key]['count']++;
        $unpaidList[] = ['id' => (int) $inv->id, 'num' => $inv->invoicenum ?: $inv->id, 'userid' => (int) $inv->userid,
            'client' => lwbd_client_name($inv), 'due' => $inv->duedate, 'late' => $late, 'balance' => $balance];
    }
    usort($unpaidList, function ($a, $b) {
        return $b['balance'] <=> $a['balance'];
    });
    $d['unpaid'] = ['buckets' => $buckets, 'total' => array_sum(array_column($buckets, 'amount')),
        'count' => array_sum(array_column($buckets, 'count')), 'top' => array_slice($unpaidList, 0, 8)];

    // Clients, services, domaines, commandes, tickets ----------------
    $d['clients'] = [
        'active' => Capsule::table('tblclients')->where('status', 'Active')->count(),
        'new_month' => Capsule::table('tblclients')->where('datecreated', '>=', date('Y-m-01'))->count(),
    ];
    $d['services'] = [
        'active' => Capsule::table('tblhosting')->where('domainstatus', 'Active')->count(),
        'suspended' => Capsule::table('tblhosting')->where('domainstatus', 'Suspended')->count(),
        'pending' => Capsule::table('tblhosting')->where('domainstatus', 'Pending')->count(),
    ];
    $d['domains'] = [
        'active' => Capsule::table('tbldomains')->where('status', 'Active')->count(),
        'grace' => Capsule::table('tbldomains')->whereIn('status', ['Grace', 'Redemption'])->count(),
    ];
    $d['orders'] = [
        'pending' => Capsule::table('tblorders')->where('status', 'Pending')->count(),
        'recent' => Capsule::table('tblorders as o')->join('tblclients as c', 'c.id', '=', 'o.userid')
            ->leftJoin('tblinvoices as i', 'i.id', '=', 'o.invoiceid')
            ->orderBy('o.id', 'desc')->limit(8)
            ->get(['o.id', 'o.ordernum', 'o.date', 'o.amount', 'o.status', 'o.userid', 'i.status as invstatus', 'c.firstname', 'c.lastname', 'c.companyname'])
            ->map(function ($o) {
                return ['id' => (int) $o->id, 'num' => $o->ordernum, 'date' => $o->date, 'amount' => (float) $o->amount,
                    'status' => $o->status, 'paid' => $o->invstatus === 'Paid', 'userid' => (int) $o->userid, 'client' => lwbd_client_name($o)];
            })->all(),
    ];
    $waiting = ['Open', 'Customer-Reply', 'In Progress', 'On Hold'];
    $tq = Capsule::table('tbltickets as t')->whereIn('t.status', $waiting);
    if (Capsule::schema()->hasColumn('tbltickets', 'merged_ticket_id')) {
        $tq->where('t.merged_ticket_id', 0);
    }
    $d['tickets'] = [
        'waiting' => (clone $tq)->count(),
        'answered' => Capsule::table('tbltickets')->where('status', 'Answered')->count(),
        'list' => $tq->leftJoin('tblclients as c', 'c.id', '=', 't.userid')->orderBy('t.lastreply')->limit(6)
            ->get(['t.id', 't.tid', 't.title', 't.status', 't.lastreply', 't.userid', 't.name', 'c.firstname', 'c.lastname', 'c.companyname'])
            ->map(function ($t) use ($today) {
                return ['id' => (int) $t->id, 'tid' => $t->tid, 'title' => $t->title, 'status' => $t->status,
                    'client' => $t->userid ? lwbd_client_name($t) : ($t->name ?: 'Visiteur'),
                    'age' => lwbd_days_between($t->lastreply, $today)];
            })->all(),
    ];

    // Renouvellements des 30 prochains jours --------------------------
    $limit = date('Y-m-d', strtotime('+30 days'));
    $renewals = [];
    $svc = Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->where('h.domainstatus', 'Active')->whereIn('h.billingcycle', array_keys(LWBD_CYCLE_MONTHS))
        ->whereBetween('h.nextduedate', [$today, $limit])
        ->get(['h.id', 'h.userid', 'h.domain', 'h.nextduedate', 'h.amount', 'p.name', 'c.firstname', 'c.lastname', 'c.companyname']);
    foreach ($svc as $s) {
        $renewals[] = ['kind' => 'service', 'id' => (int) $s->id, 'userid' => (int) $s->userid, 'client' => lwbd_client_name($s),
            'label' => $s->name . ($s->domain ? ' · ' . $s->domain : ''), 'due' => $s->nextduedate, 'amount' => (float) $s->amount,
            'invoice' => lwbd_cover_status(['Hosting'], $s->id, $s->nextduedate)];
    }
    $dom = Capsule::table('tbldomains as d')->join('tblclients as c', 'c.id', '=', 'd.userid')
        ->where('d.status', 'Active')->whereBetween('d.nextduedate', [$today, $limit])
        ->get(['d.id', 'd.userid', 'd.domain', 'd.nextduedate', 'd.recurringamount', 'c.firstname', 'c.lastname', 'c.companyname']);
    foreach ($dom as $x) {
        $renewals[] = ['kind' => 'domain', 'id' => (int) $x->id, 'userid' => (int) $x->userid, 'client' => lwbd_client_name($x),
            'label' => $x->domain, 'due' => $x->nextduedate, 'amount' => (float) $x->recurringamount,
            'invoice' => lwbd_cover_status(['Domain', 'DomainRegister', 'DomainTransfer'], $x->id, $x->nextduedate)];
    }
    usort($renewals, function ($a, $b) {
        return strcmp($a['due'], $b['due']);
    });
    $d['renewals'] = $renewals;

    // Domaines à surveiller -------------------------------------------
    $risks = [];
    $domRows = Capsule::table('tbldomains as d')->join('tblclients as c', 'c.id', '=', 'd.userid')
        ->whereIn('d.status', ['Active', 'Grace', 'Redemption'])
        ->get(['d.id', 'd.userid', 'd.domain', 'd.status', 'd.registrar', 'd.expirydate', 'd.nextduedate', 'c.firstname', 'c.lastname', 'c.companyname']);
    foreach ($domRows as $x) {
        $reason = null;
        $level = 'warning';
        if (in_array($x->status, ['Grace', 'Redemption'], true)) {
            $reason = $x->status === 'Grace' ? 'En période de grâce' : 'En rédemption (frais de restauration)';
            $level = 'critical';
        }
        if (lwbd_date_ok($x->expirydate) && lwbd_date_ok($x->nextduedate) && lwbd_days_between($x->expirydate, $x->nextduedate) > 45
            && lwbd_days_between($today, $x->expirydate) <= 30) {
            $reason = 'Payé mais pas renouvelé chez le registraire';
            $level = 'critical';
        } elseif (!$reason && lwbd_date_ok($x->expirydate) && lwbd_days_between($today, $x->expirydate) <= 15) {
            $reason = 'Expire dans ' . max(0, lwbd_days_between($today, $x->expirydate)) . ' jour(s)';
        } elseif (!$reason && $x->status === 'Active' && trim((string) $x->registrar) === '') {
            $reason = 'Aucun registraire associé';
            $level = 'info';
        }
        if ($reason) {
            $risks[] = ['id' => (int) $x->id, 'userid' => (int) $x->userid, 'domain' => $x->domain, 'client' => lwbd_client_name($x),
                'reason' => $reason, 'level' => $level, 'expiry' => lwbd_date_ok($x->expirydate) ? $x->expirydate : null];
        }
    }
    $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($risks, function ($a, $b) use ($order) {
        return [$order[$a['level']], $a['expiry']] <=> [$order[$b['level']], $b['expiry']];
    });
    $d['domain_risks'] = $risks;

    // Revenus par catégorie (factures payées sur la période) ----------
    $items = Capsule::table('tblinvoiceitems as it')->join('tblinvoices as i', 'i.id', '=', 'it.invoiceid')
        ->leftJoin('tblhosting as h', function ($j) {
            $j->on('h.id', '=', 'it.relid')->where('it.type', '=', 'Hosting');
        })
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->leftJoin('tblproductgroups as g', 'g.id', '=', 'p.gid')
        ->where('i.status', 'Paid')->where('i.datepaid', '>=', $from)
        ->selectRaw('it.type, g.name AS grp, SUM(it.amount) AS total')
        ->groupBy('it.type', 'g.name')->get();
    $cats = [];
    foreach ($items as $it) {
        if (in_array($it->type, ['Domain', 'DomainRegister', 'DomainTransfer', 'DomainAddonDNS', 'DomainAddonEMF', 'DomainAddonIDP'], true)) {
            $cat = 'Noms de domaine';
        } elseif ($it->type === 'Hosting' || $it->type === 'PromoHosting') {
            $cat = $it->grp ?: 'Services';
        } elseif ($it->type === 'Addon') {
            $cat = 'Options de services';
        } elseif ($it->type === 'LateFee') {
            $cat = 'Pénalités de retard';
        } elseif ($it->type === 'AddFunds' || $it->type === 'Invoice') {
            continue; // crédit et regroupements : déjà comptés ailleurs
        } else {
            $cat = 'Autres';
        }
        $cats[$cat] = ($cats[$cat] ?? 0) + (float) $it->total;
    }
    arsort($cats);
    $d['categories'] = array_filter($cats, function ($v) {
        return $v > 0;
    });

    // Moyens de paiement ------------------------------------------------
    $names = lwbd_gateway_names();
    $gw = [];
    foreach (Capsule::table('tblaccounts')->where('date', '>=', $from)->where('amountin', '>', 0)
        ->selectRaw('gateway, SUM(amountin) AS total, COUNT(*) AS n')->groupBy('gateway')->get() as $g) {
        $label = $names[$g->gateway] ?? ($g->gateway ?: 'Autre');
        $gw[$label] = ['total' => ($gw[$label]['total'] ?? 0) + (float) $g->total, 'count' => ($gw[$label]['count'] ?? 0) + (int) $g->n];
    }
    uasort($gw, function ($a, $b) {
        return $b['total'] <=> $a['total'];
    });
    $d['gateways'] = $gw;

    // Meilleurs clients et derniers paiements --------------------------
    $d['top_clients'] = Capsule::table('tblaccounts as a')->join('tblclients as c', 'c.id', '=', 'a.userid')
        ->where('a.date', '>=', $from)
        ->selectRaw('a.userid, c.firstname, c.lastname, c.companyname, SUM(a.amountin - a.fees - a.amountout) AS total')
        ->groupBy('a.userid', 'c.firstname', 'c.lastname', 'c.companyname')->orderByDesc('total')->limit(8)->get()
        ->map(function ($r) {
            return ['userid' => (int) $r->userid, 'client' => lwbd_client_name($r), 'total' => round((float) $r->total, 2)];
        })->all();
    $d['payments'] = Capsule::table('tblaccounts as a')->leftJoin('tblclients as c', 'c.id', '=', 'a.userid')
        ->where('a.amountin', '>', 0)->orderBy('a.date', 'desc')->orderBy('a.id', 'desc')->limit(8)
        ->get(['a.id', 'a.userid', 'a.date', 'a.amountin', 'a.gateway', 'a.invoiceid', 'c.firstname', 'c.lastname', 'c.companyname'])
        ->map(function ($p) use ($names) {
            return ['userid' => (int) $p->userid, 'client' => lwbd_client_name($p), 'date' => $p->date, 'amount' => (float) $p->amountin,
                'gateway' => $names[$p->gateway] ?? $p->gateway, 'invoice' => (int) $p->invoiceid];
        })->all();

    // Abonnements de maintenance -----------------------------------------
    $maintPids = Capsule::table('tblproducts as p')->join('tblproductgroups as g', 'g.id', '=', 'p.gid')
        ->where('g.slug', 'maintenance-site-web')->pluck('p.id')->all();
    $d['maintenance'] = $maintPids ? [
        'active' => Capsule::table('tblhosting')->whereIn('packageid', $maintPids)->where('domainstatus', 'Active')->count(),
        'pending' => Capsule::table('tblhosting')->whereIn('packageid', $maintPids)->where('domainstatus', 'Pending')->count(),
        'mrr' => round((float) Capsule::table('tblhosting')->whereIn('packageid', $maintPids)->where('domainstatus', 'Active')
            ->where('billingcycle', 'Monthly')->sum('amount'), 2),
    ] : null;

    $d['late_fees'] = round((float) ($cats['Pénalités de retard'] ?? 0), 2);
    return $d;
}

/** Réglages du tableau de bord (tbladdonmodules), avec valeurs par défaut. */
function lwbd_settings()
{
    $rows = Capsule::table('tbladdonmodules')->where('module', 'lwb_dashboard')->pluck('value', 'setting')->all();
    return [
        'goal' => (string) ($rows['goal'] ?? '500'),
        'period' => in_array((int) ($rows['period'] ?? 12), [6, 12, 24], true) ? (int) $rows['period'] : 12,
        'homepage' => (string) ($rows['homepage'] ?? ''),
        'hidden' => array_values(array_filter(explode(',', (string) ($rows['hidden'] ?? '')))),
        'access' => (string) ($rows['access'] ?? ''),
    ];
}

function lwbd_save_setting($name, $value)
{
    Capsule::table('tbladdonmodules')->updateOrInsert(['module' => 'lwb_dashboard', 'setting' => $name], ['value' => (string) $value]);
}
