<?php
/**
 * Lwb-dashboard — affichage (HTML + graphiques SVG, sans dépendance externe).
 */

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

function lwbd_e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function lwbd_money($v, $decimals = 2)
{
    return number_format((float) $v, $decimals, ',', "\u{202F}") . "\u{00A0}$";
}

function lwbd_short_money($v)
{
    return $v >= 1000 ? number_format($v / 1000, 1, ',', '') . "\u{00A0}k$" : number_format($v, 0, ',', "\u{202F}") . "\u{00A0}$";
}

function lwbd_date($date)
{
    return $date ? date('d/m/Y', strtotime($date)) : '—';
}

function lwbd_month_label($ym, $long = false)
{
    $short = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    $full = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    [$y, $m] = explode('-', $ym);
    return ($long ? $full[(int) $m - 1] . ' ' . $y : $short[(int) $m - 1]);
}

function lwbd_icon($name)
{
    $p = [
        'cash' => '<path d="M3 7h18v10H3z"/><circle cx="12" cy="12" r="2.5"/><path d="M6 10v4M18 10v4"/>',
        'repeat' => '<path d="M17 2l3 3-3 3"/><path d="M4 11V9a4 4 0 0 1 4-4h12"/><path d="M7 22l-3-3 3-3"/><path d="M20 13v2a4 4 0 0 1-4 4H4"/>',
        'alert' => '<path d="M12 3l9.5 16.5h-19z"/><path d="M12 10v4M12 17.5v.01"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a2 2 0 0 0 2 1.6h7.6a2 2 0 0 0 2-1.5L21 8H6"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
        'server' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'chat' => '<path d="M4 5h16v11H8l-4 4z"/>',
        'up' => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'back' => '<path d="M15 18l-6-6 6-6"/>',
        'brush' => '<path d="M9.5 14.5l-4 4a2.1 2.1 0 0 1-3-3l4-4"/><path d="M14 3l7 7-7.5 7.5-7-7z"/>',
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'tool' => '<path d="M14.7 6.3a4 4 0 0 0-5.4 5.2L3 17.8V21h3.2l6.3-6.3a4 4 0 0 0 5.2-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
    ][$name] ?? '';
    return '<svg class="lwbd-ic" viewBox="0 0 24 24" aria-hidden="true">' . $p . '</svg>';
}

/** Petite étiquette de statut : couleur + icône + texte, jamais la couleur seule. */
function lwbd_badge($level, $text)
{
    $sym = ['good' => '✓', 'warning' => '!', 'serious' => '!', 'critical' => '✕', 'info' => 'i', 'neutral' => '•'][$level] ?? '•';
    return '<span class="lwbd-badge lwbd-' . lwbd_e($level) . '"><b aria-hidden="true">' . $sym . '</b>' . lwbd_e($text) . '</span>';
}

function lwbd_kpi($icon, $label, $value, $sub = '', $href = null, $tone = '')
{
    $tag = $href ? 'a href="' . lwbd_e($href) . '"' : 'div';
    $end = $href ? 'a' : 'div';
    return '<' . $tag . ' class="lwbd-kpi ' . $tone . '"><span class="lwbd-kpi-ic">' . lwbd_icon($icon) . '</span>'
        . '<span class="lwbd-kpi-label">' . lwbd_e($label) . '</span>'
        . '<span class="lwbd-kpi-value">' . $value . '</span>'
        . ($sub !== '' ? '<span class="lwbd-kpi-sub">' . $sub . '</span>' : '') . '</' . $end . '>';
}

/** Histogramme des encaissements mensuels (SVG, une seule série, info-bulle au survol). */
function lwbd_revenue_chart(array $series)
{
    $w = 760;
    $h = 260;
    $l = 52;
    $r = 12;
    $t = 26;
    $b = 30;
    $max = max(1, max(array_column($series, 'net')));
    $step = [50, 100, 200, 250, 500, 1000, 2000, 2500, 5000, 10000];
    $tick = 50;
    foreach ($step as $s) {
        if ($max / $s <= 4) {
            $tick = $s;
            break;
        }
    }
    $top = ceil($max / $tick) * $tick;
    $n = count($series);
    $slot = ($w - $l - $r) / $n;
    $bw = min(38, $slot * 0.62);
    $y = function ($v) use ($h, $b, $t, $top) {
        return $h - $b - ($v / $top) * ($h - $t - $b);
    };
    $svg = '<svg class="lwbd-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="Encaissements nets par mois">';
    for ($v = 0; $v <= $top; $v += $tick) {
        $yy = round($y($v), 1);
        $svg .= '<line class="lwbd-gl' . ($v == 0 ? ' lwbd-base' : '') . '" x1="' . $l . '" x2="' . ($w - $r) . '" y1="' . $yy . '" y2="' . $yy . '"/>'
            . '<text class="lwbd-axis" x="' . ($l - 8) . '" y="' . ($yy + 4) . '" text-anchor="end">' . lwbd_e(lwbd_short_money($v)) . '</text>';
    }
    $maxIdx = array_search(max(array_column($series, 'net')), array_column($series, 'net'));
    foreach ($series as $i => $p) {
        $cx = $l + $slot * $i + $slot / 2;
        $x = round($cx - $bw / 2, 1);
        $v = max(0, $p['net']);
        $y0 = $y(0);
        $y1 = $y($v);
        $hgt = $y0 - $y1;
        $rad = min(4, $hgt);
        $current = $i === $n - 1;
        $tip = lwbd_month_label($p['month'], true) . ($current ? ' (en cours)' : '') . '|' . lwbd_money($p['net']) . '|' . $p['count'] . ' paiement(s)';
        $svg .= '<g class="lwbd-bar' . ($current ? ' is-current' : '') . '" data-tip="' . lwbd_e($tip) . '" tabindex="0">'
            . '<rect class="lwbd-hit" x="' . round($cx - $slot / 2, 1) . '" y="' . $t . '" width="' . round($slot, 1) . '" height="' . ($h - $t - $b) . '"/>';
        if ($hgt > 0.5) {
            $svg .= '<path class="lwbd-mark" d="M' . $x . ' ' . round($y0, 1) . 'V' . round($y1 + $rad, 1)
                . 'Q' . $x . ' ' . round($y1, 1) . ' ' . round($x + $rad, 1) . ' ' . round($y1, 1)
                . 'H' . round($x + $bw - $rad, 1) . 'Q' . round($x + $bw, 1) . ' ' . round($y1, 1) . ' ' . round($x + $bw, 1) . ' ' . round($y1 + $rad, 1)
                . 'V' . round($y0, 1) . 'Z"/>';
        }
        if ($i === $maxIdx || $current) {
            $svg .= '<text class="lwbd-val" x="' . round($cx, 1) . '" y="' . round($y1 - 7, 1) . '" text-anchor="middle">' . lwbd_e(lwbd_short_money($p['net'])) . '</text>';
        }
        if ($n <= 12 || $i % 2 === ($n - 1) % 2) {
            $svg .= '<text class="lwbd-axis" x="' . round($cx, 1) . '" y="' . ($h - 10) . '" text-anchor="middle">' . lwbd_e(lwbd_month_label($p['month'])) . '</text>';
        }
        $svg .= '</g>';
    }
    return $svg . '</svg>';
}

/** Barres horizontales en HTML : une ligne par élément, valeur écrite à côté. */
function lwbd_hbars(array $rows, $tone = 'series')
{
    $max = max(1, max(array_map(function ($r) {
        return $r['value'];
    }, $rows ?: [['value' => 1]])));
    $html = '<div class="lwbd-hbars">';
    foreach ($rows as $row) {
        $pct = max(1.5, round($row['value'] / $max * 100, 1));
        $html .= '<div class="lwbd-hrow" data-tip="' . lwbd_e($row['label'] . '|' . $row['text'] . (isset($row['note']) ? '|' . $row['note'] : '')) . '">'
            . '<div class="lwbd-hlabel">' . ($row['badge'] ?? lwbd_e($row['label'])) . '</div>'
            . '<div class="lwbd-htrack"><span class="lwbd-hfill lwbd-' . lwbd_e($row['tone'] ?? $tone) . '" style="width:' . $pct . '%"></span></div>'
            . '<div class="lwbd-hval">' . lwbd_e($row['text']) . (isset($row['note']) ? '<small>' . lwbd_e($row['note']) . '</small>' : '') . '</div></div>';
    }
    return $html . '</div>';
}

function lwbd_card($title, $body, $extra = '', $class = '')
{
    return '<section class="lwbd-card ' . $class . '"><header class="lwbd-card-h"><h2>' . lwbd_e($title) . '</h2>' . $extra . '</header>' . $body . '</section>';
}

function lwbd_empty($text)
{
    return '<p class="lwbd-empty">' . lwbd_e($text) . '</p>';
}

function lwbd_render(array $d, $modulelink, array $opt = [])
{
    $token = $opt['token'] ?? '';
    $m = $d['month'];
    $delta = $m['last_to_date'] > 0 ? round(($m['current'] - $m['last_to_date']) / $m['last_to_date'] * 100) : null;
    $deltaHtml = $delta === null ? 'Rien à la même date le mois dernier'
        : '<span class="lwbd-delta ' . ($delta >= 0 ? 'up' : 'down') . '">' . lwbd_icon($delta >= 0 ? 'up' : 'down') . ($delta >= 0 ? '+' : '') . $delta . '&nbsp;%</span> vs même date le mois dernier';

    $u = $d['unpaid'];
    $late = $u['buckets']['d30']['amount'] + $u['buckets']['d90']['amount'] + $u['buckets']['old']['amount'];

    $kpis = '<div class="lwbd-kpis">'
        . lwbd_kpi('cash', 'Encaissé en ' . lwbd_month_label(date('Y-m'), true), lwbd_e(lwbd_money($m['current'])), $deltaHtml, 'transactions.php')
        . lwbd_kpi('repeat', 'Revenu récurrent mensuel', lwbd_e(lwbd_money($d['mrr'])), 'Soit ' . lwbd_e(lwbd_money($d['mrr'] * 12, 0)) . ' par an')
        . lwbd_kpi('alert', 'Impayés', lwbd_e(lwbd_money($u['total'])), $u['count'] . ' facture(s), dont ' . lwbd_e(lwbd_money($late)) . ' en retard', 'invoices.php?status=Unpaid', $late > 0 ? 'is-alert' : '')
        . lwbd_kpi('cart', 'Commandes en attente', (string) $d['orders']['pending'], $d['orders']['pending'] ? 'À valider' : 'Rien à traiter', 'orders.php?status=Pending', $d['orders']['pending'] ? 'is-alert' : '')
        . lwbd_kpi('users', 'Clients actifs', (string) $d['clients']['active'], '+' . $d['clients']['new_month'] . ' ce mois-ci', 'clients.php')
        . lwbd_kpi('server', 'Services actifs', (string) $d['services']['active'], $d['services']['suspended'] . ' suspendu(s) · ' . $d['services']['pending'] . ' en attente', 'clientshostinglist.php')
        . lwbd_kpi('globe', 'Domaines actifs', (string) $d['domains']['active'], $d['domains']['grace'] ? '<b class="lwbd-txt-critical">' . $d['domains']['grace'] . ' en période de grâce</b>' : 'Aucun en période de grâce', 'clientsdomainlist.php', $d['domains']['grace'] ? 'is-alert' : '')
        . lwbd_kpi('chat', 'Tickets à traiter', (string) $d['tickets']['waiting'], $d['tickets']['answered'] . ' en attente du client', 'supporttickets.php', $d['tickets']['waiting'] ? 'is-alert' : '')
        . '</div>';

    // Période
    $chips = '<nav class="lwbd-chips" aria-label="Période">';
    foreach ([6, 12, 24] as $p) {
        $chips .= '<a href="' . lwbd_e($modulelink . '&p=' . $p) . '"' . ($p === $d['months'] ? ' class="on" aria-current="true"' : '') . '>' . $p . ' mois</a>';
    }
    $chips .= '</nav>';

    // Graphique des encaissements + tableau
    $rows = '';
    foreach (array_reverse($d['revenue']) as $p) {
        $rows .= '<tr><td>' . lwbd_e(lwbd_month_label($p['month'], true)) . '</td><td class="num">' . lwbd_e(lwbd_money($p['net'])) . '</td><td class="num">' . $p['count'] . '</td></tr>';
    }
    $avg = $d['revenue_total'] / max(1, count($d['revenue']));
    $revenue = '<div class="lwbd-hero"><span>' . lwbd_e(lwbd_money($d['revenue_total'])) . '</span> encaissés sur ' . $d['months'] . ' mois · moyenne ' . lwbd_e(lwbd_money($avg)) . ' par mois</div>'
        . '<div class="lwbd-chart-wrap">' . lwbd_revenue_chart($d['revenue']) . '</div>'
        . '<details class="lwbd-data"><summary>Voir les données</summary><table class="lwbd-table"><thead><tr><th>Mois</th><th class="num">Net encaissé</th><th class="num">Paiements</th></tr></thead><tbody>' . $rows . '</tbody></table></details>';

    // Impayés par ancienneté
    $tones = ['upcoming' => 'info', 'd30' => 'warning', 'd90' => 'serious', 'old' => 'critical'];
    $aging = [];
    foreach ($u['buckets'] as $k => $bk) {
        $aging[] = ['label' => $bk['label'], 'value' => $bk['amount'], 'text' => lwbd_money($bk['amount']), 'note' => $bk['count'] . ' facture(s)',
            'tone' => $tones[$k], 'badge' => lwbd_badge($tones[$k], $bk['label'])];
    }
    $topUnpaid = '';
    foreach ($u['top'] as $inv) {
        $badge = $inv['late'] > 0 ? lwbd_badge($inv['late'] > 90 ? 'critical' : ($inv['late'] > 30 ? 'serious' : 'warning'), $inv['late'] . ' j de retard') : lwbd_badge('info', 'Échéance ' . lwbd_date($inv['due']));
        $topUnpaid .= '<tr><td><a href="invoices.php?action=edit&id=' . $inv['id'] . '"><b>n°&nbsp;' . lwbd_e($inv['num']) . '</b></a>'
            . '<small><a href="clientssummary.php?userid=' . $inv['userid'] . '">' . lwbd_e($inv['client']) . '</a></small><div class="lwbd-cellbadge">' . $badge . '</div></td>'
            . '<td class="num"><b>' . lwbd_e(lwbd_money($inv['balance'])) . '</b>'
            . '<form method="post" action="' . lwbd_e($modulelink) . '" class="lwbd-inline" onsubmit="return confirm(\'Envoyer une relance pour la facture n° ' . lwbd_e($inv['num']) . ' ?\')">'
            . $token . '<input type="hidden" name="lwbd_action" value="remind"><input type="hidden" name="invoice" value="' . $inv['id'] . '">'
            . '<button type="submit" class="lwbd-mini-btn" title="Envoyer un e-mail de relance">Relancer</button></form></td></tr>';
    }
    $unpaidCard = lwbd_hbars($aging)
        . ($topUnpaid ? '<h3 class="lwbd-sub">Plus gros soldes</h3><div class="lwbd-scroll"><table class="lwbd-table" id="lwbd-t-unpaid" data-name="impayes"><thead><tr><th>Facture · client</th><th class="num">Solde</th></tr></thead><tbody>' . $topUnpaid . '</tbody></table></div>' : '');

    // Renouvellements
    $ren = '';
    foreach ($d['renewals'] as $ri => $x) {
        $href = $x['kind'] === 'domain' ? 'clientsdomains.php?userid=' . $x['userid'] . '&id=' . $x['id'] : 'clientsservices.php?userid=' . $x['userid'] . '&id=' . $x['id'];
        $inv = $x['invoice'];
        $st = !$inv ? lwbd_badge('neutral', 'Pas encore facturé') : ($inv['status'] === 'Paid' ? lwbd_badge('good', 'Payé') : ($inv['status'] === 'Unpaid' ? lwbd_badge('warning', 'Facture impayée') : lwbd_badge('neutral', $inv['status'])));
        if ($inv) {
            $st = '<a href="invoices.php?action=edit&id=' . $inv['id'] . '">' . $st . '</a>';
        }
        $days = lwbd_days_between($d['today'], $x['due']);
        $ren .= '<tr' . ($ri >= 14 ? ' class="lwbd-extra" hidden' : '') . '><td class="nowrap"><b>' . lwbd_e(lwbd_date($x['due'])) . '</b><small>' . ($days === 0 ? "aujourd'hui" : 'dans ' . $days . ' j') . '</small></td>'
            . '<td><a href="' . $href . '">' . lwbd_e($x['label']) . '</a><small><a href="clientssummary.php?userid=' . $x['userid'] . '">' . lwbd_e($x['client']) . '</a></small></td>'
            . '<td>' . $st . '</td><td class="num">' . lwbd_e(lwbd_money($x['amount'])) . '</td></tr>';
    }
    $renCount = count($d['renewals']);
    $renewals = $ren ? '<div class="lwbd-scroll"><table class="lwbd-table" id="lwbd-t-renew" data-name="renouvellements"><thead><tr><th>Échéance</th><th>Service · client</th><th>Facture</th><th class="num">Montant</th></tr></thead><tbody>' . $ren . '</tbody></table></div>'
        . ($renCount > 14 ? '<button type="button" class="lwbd-more lwbd-showmore" data-target="lwbd-t-renew">Afficher les ' . ($renCount - 14) . ' autre(s) échéance(s)</button>' : '')
        : lwbd_empty('Aucun renouvellement dans les 30 prochains jours.');

    // Domaines à surveiller
    $risk = '';
    foreach (array_slice($d['domain_risks'], 0, 10) as $x) {
        $risk .= '<li><div><a href="clientsdomains.php?userid=' . $x['userid'] . '&id=' . $x['id'] . '"><b>' . lwbd_e($x['domain']) . '</b></a>'
            . '<small><a href="clientssummary.php?userid=' . $x['userid'] . '">' . lwbd_e($x['client']) . '</a>' . ($x['expiry'] ? ' · expire le ' . lwbd_e(lwbd_date($x['expiry'])) : '') . '</small></div>'
            . lwbd_badge($x['level'], $x['reason']) . '</li>';
    }
    $risks = $risk ? '<ul class="lwbd-list" id="lwbd-t-risks" data-name="domaines-a-surveiller">' . $risk . '</ul>' . (count($d['domain_risks']) > 10 ? '<p class="lwbd-more">+ ' . (count($d['domain_risks']) - 10) . ' autre(s)</p>' : '')
        : lwbd_empty('Aucun domaine à risque.');

    // Catégories et moyens de paiement
    $catRows = [];
    $catTotal = array_sum($d['categories']) ?: 1;
    foreach ($d['categories'] as $label => $v) {
        $catRows[] = ['label' => $label, 'value' => $v, 'text' => lwbd_money($v), 'note' => round($v / $catTotal * 100) . ' %'];
    }
    $gwRows = [];
    $gwTotal = array_sum(array_column($d['gateways'], 'total')) ?: 1;
    foreach ($d['gateways'] as $label => $g) {
        $gwRows[] = ['label' => $label, 'value' => $g['total'], 'text' => lwbd_money($g['total']), 'note' => round($g['total'] / $gwTotal * 100) . ' % · ' . $g['count'] . ' paiement(s)'];
    }

    // Commandes, paiements, tickets, clients
    $orders = '';
    foreach ($d['orders']['recent'] as $o) {
        $st = $o['status'] === 'Pending' ? lwbd_badge('warning', 'En attente') : ($o['status'] === 'Active' ? lwbd_badge('good', 'Active') : ($o['status'] === 'Cancelled' ? lwbd_badge('neutral', 'Annulée') : lwbd_badge('serious', $o['status'])));
        $orders .= '<tr><td><a href="orders.php?action=view&id=' . $o['id'] . '">n°&nbsp;' . lwbd_e($o['num']) . '</a><small>' . lwbd_e(lwbd_date($o['date'])) . '</small></td>'
            . '<td><a href="clientssummary.php?userid=' . $o['userid'] . '">' . lwbd_e($o['client']) . '</a></td><td>' . $st . '</td><td class="num">' . lwbd_e(lwbd_money($o['amount'])) . '</td></tr>';
    }
    $pay = '';
    foreach ($d['payments'] as $p) {
        $pay .= '<tr><td class="nowrap">' . lwbd_e(lwbd_date($p['date'])) . '</td><td><a href="clientssummary.php?userid=' . $p['userid'] . '">' . lwbd_e($p['client']) . '</a><small>'
            . lwbd_e($p['gateway']) . ($p['invoice'] ? ' · <a href="invoices.php?action=edit&id=' . $p['invoice'] . '">facture ' . $p['invoice'] . '</a>' : '') . '</small></td>'
            . '<td class="num"><b>' . lwbd_e(lwbd_money($p['amount'])) . '</b></td></tr>';
    }
    $tick = '';
    $stFr = ['Open' => 'Ouvert', 'Customer-Reply' => 'Réponse client', 'In Progress' => 'En cours', 'On Hold' => 'En pause'];
    foreach ($d['tickets']['list'] as $t) {
        $tick .= '<li><div><a href="supporttickets.php?action=view&id=' . $t['id'] . '"><b>#' . lwbd_e($t['tid']) . ' ' . lwbd_e($t['title']) . '</b></a><small>' . lwbd_e($t['client']) . ' · ' . lwbd_e($stFr[$t['status']] ?? $t['status']) . '</small></div>'
            . lwbd_badge($t['age'] >= 2 ? 'serious' : 'info', $t['age'] === 0 ? "Aujourd'hui" : 'Depuis ' . $t['age'] . ' j') . '</li>';
    }
    $top = [];
    foreach ($d['top_clients'] as $c) {
        $top[] = ['label' => $c['client'], 'value' => max(0, $c['total']), 'text' => lwbd_money($c['total']),
            'badge' => '<a href="clientssummary.php?userid=' . $c['userid'] . '">' . lwbd_e($c['client']) . '</a>'];
    }

    $maint = '';
    if ($d['maintenance']) {
        $mt = $d['maintenance'];
        $maint = '<div class="lwbd-mini"><span class="lwbd-kpi-ic">' . lwbd_icon('tool') . '</span><div><b>Maintenance de site web</b><small>'
            . $mt['active'] . ' abonnement(s) actif(s) · ' . $mt['pending'] . ' en attente de paiement · ' . lwbd_e(lwbd_money($mt['mrr'])) . ' par mois</small></div></div>';
    }
    $lateFees = $d['late_fees'] > 0 ? '<p class="lwbd-note">Pénalités de retard encaissées sur la période&nbsp;: <b>' . lwbd_e(lwbd_money($d['late_fees'])) . '</b></p>' : '';

    $actions = '<div class="lwbd-actions">'
        . '<a class="lwbd-btn primary" href="invoices.php?action=createinvoice">+ Facture</a>'
        . '<a class="lwbd-btn" href="ordersadd.php">+ Commande</a>'
        . '<a class="lwbd-btn" href="clientsadd.php">+ Client</a>'
        . '<a class="lwbd-btn" href="supporttickets.php?action=open">+ Ticket</a>'
        . '<a class="lwbd-btn ghost" href="' . lwbd_e($modulelink . '&view=settings') . '">' . lwbd_icon('gear') . 'Réglages</a></div>';

    $flash = '';
    if (!empty($opt['flash'])) {
        [$type, $msg] = $opt['flash'];
        $level = ['success' => 'good', 'warning' => 'warning', 'danger' => 'critical'][$type] ?? 'info';
        $flash = '<div class="lwbd-flash lwbd-' . $level . '" role="status">' . lwbd_e($msg) . '</div>';
    }

    $hidden = $opt['hidden'] ?? [];
    $show = function ($key) use ($hidden) {
        return !in_array($key, $hidden, true);
    };
    $exp = function ($id) {
        return '<button type="button" class="lwbd-export" data-export="' . $id . '" title="Télécharger en CSV (Excel)">CSV</button>';
    };
    // Les cartes vont par paires ; si l'une est masquée, l'autre prend toute la largeur.
    $pairs = [
        [['chart', 'span-8', fn () => lwbd_card('Encaissements nets par mois', $revenue, $chips)],
         ['goal', 'span-4', fn () => isset($opt['forecast']) ? lwbd_card('Objectif de ' . lwbd_month_label(date('Y-m'), true), lwbd_goal_html($opt['forecast'], $d)) : '']],
        [['unpaid', 'span-4', fn () => lwbd_card('Impayés par ancienneté', $unpaidCard, '<span class="lwbd-tools">' . $exp('lwbd-t-unpaid') . '<a class="lwbd-link" href="invoices.php?status=Unpaid">Toutes →</a></span>')],
         ['renewals', 'span-8', fn () => lwbd_card('À renouveler dans les 30 jours', $renewals, '<span class="lwbd-tools">' . ($ren ? $exp('lwbd-t-renew') : '') . '<span class="lwbd-count">' . $renCount . '</span></span>')]],
        [['risks', 'span-6', fn () => lwbd_card('Domaines à surveiller', $risks, '<span class="lwbd-tools">' . ($risk ? $exp('lwbd-t-risks') : '') . '<span class="lwbd-count">' . count($d['domain_risks']) . '</span></span>')],
         ['health', 'span-6', fn () => isset($opt['health']) ? lwbd_card('Santé du système', lwbd_health_html($opt['health'])) : '']],
        [['categories', 'span-6', fn () => lwbd_card('Revenus par activité · ' . $d['months'] . ' mois', ($catRows ? lwbd_hbars($catRows) : lwbd_empty('Aucune facture payée sur la période.')) . $lateFees . $maint)],
         ['gateways', 'span-6', fn () => lwbd_card('Moyens de paiement · ' . $d['months'] . ' mois', $gwRows ? lwbd_hbars($gwRows) : lwbd_empty('Aucun paiement sur la période.'))]],
        [['orders', 'span-6', fn () => lwbd_card('Dernières commandes', $orders ? '<div class="lwbd-scroll"><table class="lwbd-table">' . $orders . '</table></div>' : lwbd_empty('Aucune commande.'), '<a class="lwbd-link" href="orders.php">Toutes →</a>')],
         ['payments', 'span-6', fn () => lwbd_card('Derniers paiements', $pay ? '<div class="lwbd-scroll"><table class="lwbd-table">' . $pay . '</table></div>' : lwbd_empty('Aucun paiement.'), '<a class="lwbd-link" href="transactions.php">Toutes →</a>')]],
        [['tickets', 'span-6', fn () => lwbd_card('Tickets à traiter', $tick ? '<ul class="lwbd-list">' . $tick . '</ul>' : lwbd_empty('Aucun ticket en attente de réponse. 👍'), '<a class="lwbd-link" href="supporttickets.php">Tous →</a>')],
         ['top', 'span-6', fn () => lwbd_card('Meilleurs clients · ' . $d['months'] . ' mois', $top ? lwbd_hbars($top) : lwbd_empty('Aucun paiement sur la période.'))]],
    ];
    $grid = '';
    foreach ($pairs as $pair) {
        $cards = [];
        foreach ($pair as [$key, $span, $make]) {
            if ($key === 'chart' || $show($key)) {
                $html = $make();
                if ($html !== '') {
                    $cards[] = [$span, $html];
                }
            }
        }
        foreach ($cards as [$span, $html]) {
            $grid .= preg_replace('/^<section class="lwbd-card /', '<section class="lwbd-card ' . (count($cards) === 1 ? 'span-12' : $span) . ' ', $html, 1);
        }
    }

    $html = '<div class="lwbd" id="lwbd">'
        . '<div class="lwbd-head"><div><p class="lwbd-eyebrow">LEWEBMAX · ' . lwbd_e(lwbd_date($d['today'])) . ' · <a href="index.php?lwbd=classic">Accueil WHMCS classique</a></p><h1>Tableau de bord</h1></div>' . $actions . '</div>'
        . $flash
        . ($show('kpis') ? $kpis : '')
        . '<div class="lwbd-grid">' . $grid . '</div><div class="lwbd-tip" role="tooltip" hidden></div></div>';

    return lwbd_style() . $html . lwbd_script();
}

/** Carte « Objectif du mois » : progression, projection et encaissements attendus. */
function lwbd_goal_html(array $f, array $d)
{
    $html = '';
    if ($f['goal'] > 0) {
        $pct = (int) $f['pct'];
        $level = $pct >= 100 ? 'good' : ($f['pace'] >= $f['goal'] ? 'info' : 'warning');
        $html .= '<div class="lwbd-goal"><div class="lwbd-goal-top"><span class="lwbd-goal-pct">' . $pct . '&nbsp;%</span>'
            . '<span>' . lwbd_e(lwbd_money($f['current'])) . ' sur ' . lwbd_e(lwbd_money($f['goal'], 0)) . '</span></div>'
            . '<div class="lwbd-htrack lwbd-goal-track" role="progressbar" aria-valuenow="' . min(100, $pct) . '" aria-valuemin="0" aria-valuemax="100">'
            . '<span class="lwbd-hfill lwbd-' . ($pct >= 100 ? 'good' : 'series') . '" style="width:' . min(100, max(1.5, $pct)) . '%"></span></div>'
            . '<p class="lwbd-goal-msg">' . ($pct >= 100 ? lwbd_badge('good', 'Objectif atteint')
                : lwbd_badge($level, $f['pace'] >= $f['goal'] ? 'En bonne voie' : 'En dessous du rythme') . ' encore ' . lwbd_e(lwbd_money(max(0, $f['goal'] - $f['current']))) . ' en ' . $f['days_left'] . ' jour(s)')
            . '</p></div>';
    } else {
        $html .= '<p class="lwbd-empty">Fixez un objectif mensuel dans les réglages du tableau de bord pour suivre votre progression.</p>';
    }
    $rows = [
        ['Projection fin de mois (rythme actuel)', lwbd_money($f['pace'])],
        ['Factures dues d\'ici la fin du mois', lwbd_money($f['due_this_month'])],
        ['Renouvellements à encaisser sous 30 jours', lwbd_money($f['expected_30'])],
        ['Revenu récurrent annuel', lwbd_money($d['mrr'] * 12, 0)],
    ];
    $html .= '<dl class="lwbd-dl">';
    foreach ($rows as $r) {
        $html .= '<div><dt>' . lwbd_e($r[0]) . '</dt><dd>' . lwbd_e($r[1]) . '</dd></div>';
    }
    return $html . '</dl>';
}

/** Carte « Santé du système » : cron, registraire, erreurs, résiliations. */
function lwbd_health_html(array $h)
{
    $items = [];
    $items[] = [$h['cron']['ok'] ? 'good' : 'critical', 'Cron WHMCS', $h['cron']['ok'] ? 'Dernier passage ' . lwbd_ago($h['cron']['minutes']) : 'Dernier passage ' . lwbd_ago($h['cron']['minutes']) . ' : relances et renouvellements à l\'arrêt', 'systemhealthandupdates.php'];
    $items[] = [$h['daily']['ok'] ? 'good' : 'critical', 'Tâches quotidiennes', 'Dernière exécution ' . lwbd_ago($h['daily']['minutes']), 'automationstatus.php'];
    if ($h['registrar']['no_credit']) {
        $items[] = ['critical', 'Crédit du registraire épuisé', 'Porkbun refuse les enregistrements et renouvellements faute de crédit. Rechargez le compte Porkbun.', 'https://porkbun.com/account/credit'];
    }
    $items[] = [$h['registrar']['count'] ? 'serious' : 'good', 'Opérations de domaines',
        $h['registrar']['count'] ? $h['registrar']['count'] . ' échec(s) d\'enregistrement ou de renouvellement sur 7 jours' : 'Aucun échec sur 7 jours', 'systemactivitylog.php'];
    $items[] = [$h['errors_count'] ? 'warning' : 'good', 'Erreurs techniques', $h['errors_count'] ? $h['errors_count'] . ' erreur(s) distincte(s) sur 7 jours' : 'Aucune erreur sur 7 jours', 'systemactivitylog.php'];
    if ($h['paid_pending']) {
        $items[] = ['warning', 'Services payés non activés', count($h['paid_pending']) . ' service(s) payé(s) toujours « En attente »', 'clientshostinglist.php?status=Pending'];
    }
    if ($h['cancels']) {
        $items[] = ['warning', 'Demandes de résiliation', count($h['cancels']) . ' demande(s) sur des services encore actifs', 'cancelrequests.php'];
    }
    $html = '<ul class="lwbd-list lwbd-health">';
    foreach ($items as [$level, $title, $text, $href]) {
        $ext = strpos($href, 'http') === 0 ? ' target="_blank" rel="noopener"' : '';
        $html .= '<li><div><a href="' . lwbd_e($href) . '"' . $ext . '><b>' . lwbd_e($title) . '</b></a><small>' . lwbd_e($text) . '</small></div>'
            . lwbd_badge($level, ['good' => 'OK', 'warning' => 'À vérifier', 'serious' => 'Attention', 'critical' => 'Urgent'][$level]) . '</li>';
    }
    $html .= '</ul>';
    $logs = array_merge(array_map(function ($r) {
        return $r + ['count' => 1];
    }, $h['registrar']['last']), $h['errors']);
    if ($logs) {
        $html .= '<details class="lwbd-data"><summary>Derniers messages du journal</summary><ul class="lwbd-log">';
        foreach (array_slice($logs, 0, 8) as $l) {
            $html .= '<li><time>' . lwbd_e(date('d/m H:i', strtotime($l['date']))) . '</time>' . lwbd_e($l['text']) . ($l['count'] > 1 ? ' <b>×' . $l['count'] . '</b>' : '') . '</li>';
        }
        $html .= '</ul></details>';
    }
    return $html . '<p class="lwbd-note">' . $h['emails_today'] . ' e-mail(s) envoyé(s) aujourd\'hui.</p>';
}

/** Sections que l'on peut masquer : clé => [titre, description]. */
function lwbd_sections()
{
    return [
        'kpis' => ['Indicateurs clés', 'Les 8 chiffres en haut de page.'],
        'goal' => ['Objectif du mois', 'Jauge, projection et encaissements attendus.'],
        'unpaid' => ['Impayés par ancienneté', 'Retards et plus gros soldes, avec le bouton « Relancer ».'],
        'renewals' => ['Renouvellements à 30 jours', 'Échéances à venir et état des factures.'],
        'risks' => ['Domaines à surveiller', 'Période de grâce, payés non renouvelés, sans registraire.'],
        'health' => ['Santé du système', 'Cron, registraire, erreurs, résiliations.'],
        'categories' => ['Revenus par activité', 'Répartition par catégorie de produits.'],
        'gateways' => ['Moyens de paiement', 'PayPal, FlexPay…'],
        'orders' => ['Dernières commandes', 'Les 8 dernières commandes.'],
        'payments' => ['Derniers paiements', 'Les 8 derniers encaissements.'],
        'tickets' => ['Tickets à traiter', 'Tickets en attente de votre réponse.'],
        'top' => ['Meilleurs clients', 'Clients qui ont le plus payé sur la période.'],
    ];
}

function lwbd_switch($name, $checked, $label, $desc, $value = 'on')
{
    $id = 'lwbd-' . preg_replace('/[^a-z0-9]+/i', '-', $name . '-' . $value);
    return '<label class="lwbd-switch-row" for="' . $id . '"><span><b>' . lwbd_e($label) . '</b><small>' . lwbd_e($desc) . '</small></span>'
        . '<input type="checkbox" class="lwbd-switch" id="' . $id . '" name="' . lwbd_e($name) . '" value="' . lwbd_e($value) . '"' . ($checked ? ' checked' : '') . '></label>';
}

/** Page « Réglages » du tableau de bord, dans le même design. */
function lwbd_settings_render(array $set, $modulelink, $token, $flash = null)
{
    $hidden = $set['hidden'];
    $period = (int) $set['period'];
    $seg = '';
    foreach ([6, 12, 24] as $p) {
        $seg .= '<label class="lwbd-seg"><input type="radio" name="period" value="' . $p . '"' . ($p === $period ? ' checked' : '') . '><span>' . $p . ' mois</span></label>';
    }
    $sections = '';
    foreach (lwbd_sections() as $key => [$label, $desc]) {
        $sections .= lwbd_switch('show[]', !in_array($key, $hidden, true), $label, $desc, $key);
    }
    $msg = '';
    if ($flash) {
        $level = ['success' => 'good', 'warning' => 'warning', 'danger' => 'critical'][$flash[0]] ?? 'info';
        $msg = '<div class="lwbd-flash lwbd-' . $level . '" role="status">' . lwbd_e($flash[1]) . '</div>';
    }
    $html = '<div class="lwbd lwbd-settings" id="lwbd">'
        . '<div class="lwbd-head"><div><p class="lwbd-eyebrow"><a href="' . lwbd_e($modulelink) . '">' . lwbd_icon('back') . 'Tableau de bord</a></p><h1>Réglages</h1></div></div>'
        . $msg
        . '<form method="post" action="' . lwbd_e($modulelink . '&view=settings') . '" class="lwbd-form">' . $token
        . '<input type="hidden" name="lwbd_action" value="settings">'
        . '<div class="lwbd-set-grid">'
        . '<section class="lwbd-card"><header class="lwbd-set-h"><span class="lwbd-kpi-ic">' . lwbd_icon('target') . '</span><div><h2>Objectif et période</h2><p>Ce que vous visez chaque mois et la période des graphiques.</p></div></header>'
        . '<label class="lwbd-field"><span>Objectif d\'encaissement mensuel</span><span class="lwbd-input"><input type="number" name="goal" min="0" step="10" inputmode="decimal" value="' . lwbd_e($set['goal']) . '" placeholder="Aucun"><em>$ / mois</em></span>'
        . '<small>Laissez vide pour masquer la jauge « Objectif du mois ».</small></label>'
        . '<div class="lwbd-field"><span>Période affichée à l\'ouverture</span><div class="lwbd-segs" role="radiogroup">' . $seg . '</div></div>'
        . '</section>'
        . '<section class="lwbd-card"><header class="lwbd-set-h"><span class="lwbd-kpi-ic">' . lwbd_icon('home') . '</span><div><h2>Démarrage</h2><p>Ce que vous voyez en vous connectant à l\'administration.</p></div></header>'
        . lwbd_switch('homepage', $set['homepage'] === 'on', 'Ouvrir le tableau de bord après la connexion', 'À la place de l\'accueil WHMCS. Le lien « Accueil WHMCS classique » reste disponible en haut du tableau de bord.')
        . '<div class="lwbd-set-note">' . lwbd_icon('lock') . '<span>Les administrateurs autorisés à voir le tableau de bord se choisissent dans WHMCS, rubrique <a href="configaddonmods.php#lwb_dashboard">Modules complémentaires</a>.</span></div>'
        . '</section>'
        . '<section class="lwbd-card lwbd-set-wide"><header class="lwbd-set-h"><span class="lwbd-kpi-ic">' . lwbd_icon('brush') . '</span><div><h2>Thème de l\'administration</h2><p>Le design LEWEBMAX sur toutes les pages de WHMCS : menu latéral, barre de recherche, tableaux, formulaires et page de connexion.</p></div></header>'
        . lwbd_switch('admin_theme', $set['admin_theme'] === 'on', 'Thème moderne LEWEBMAX pour toute l\'administration', 'Pour tous les administrateurs. Désactivez-le pour retrouver instantanément le thème WHMCS d\'origine.')
        . '</section>'
        . '<section class="lwbd-card lwbd-set-wide"><header class="lwbd-set-h"><span class="lwbd-kpi-ic">' . lwbd_icon('eye') . '</span><div><h2>Sections affichées</h2><p>Masquez ce dont vous n\'avez pas besoin. Le graphique des encaissements reste toujours affiché.</p></div>'
        . '<span class="lwbd-tools"><button type="button" class="lwbd-mini-btn" data-check="all">Tout afficher</button></span></header>'
        . '<div class="lwbd-switch-grid">' . $sections . '</div></section>'
        . '</div>'
        . '<div class="lwbd-savebar"><span>Les changements s\'appliquent dès l\'enregistrement.</span><div><a class="lwbd-btn" href="' . lwbd_e($modulelink) . '">Annuler</a><button type="submit" class="lwbd-btn primary">Enregistrer</button></div></div>'
        . '</form></div>';
    return lwbd_style() . $html . lwbd_script();
}

function lwbd_style()
{
    return '<link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet"><style>' . file_get_contents(__DIR__ . '/../assets/dashboard.css') . '</style>';
}

function lwbd_script()
{
    return '<script>' . file_get_contents(__DIR__ . '/../assets/dashboard.js') . '</script>';
}
