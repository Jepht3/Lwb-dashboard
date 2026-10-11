<?php
/**
 * Lwb-dashboard — file « À faire » : tout ce qui attend une action, au même endroit.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

const LWBD_SNOOZE_TABLE = 'mod_lwbd_snooze';

function lwbd_snooze_table()
{
    if (!Capsule::schema()->hasTable(LWBD_SNOOZE_TABLE)) {
        Capsule::schema()->create(LWBD_SNOOZE_TABLE, function ($t) {
            $t->string('item', 64)->primary();
            $t->dateTime('until');
        });
    }
}

/** Éléments reportés et encore masqués : [clé => date de retour]. */
function lwbd_snoozed()
{
    lwbd_snooze_table();
    Capsule::table(LWBD_SNOOZE_TABLE)->where('until', '<', date('Y-m-d H:i:s'))->delete();
    return Capsule::table(LWBD_SNOOZE_TABLE)->pluck('until', 'item')->all();
}

/** Numéro WhatsApp (chiffres seulement, indicatif compris) à partir du téléphone WHMCS. */
function lwbd_wa_phone($phone, $country = 'CD')
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '00') === 0) {
        $digits = substr($digits, 2);
    }
    if (strpos($digits, '0') === 0 && $country === 'CD') {
        $digits = '243' . substr($digits, 1);
    } elseif (strlen($digits) === 9 && $country === 'CD') {
        $digits = '243' . $digits;
    }
    return strlen($digits) >= 10 ? $digits : '';
}

/** Lien de paiement sans connexion (FlexPay si actif, sinon la facture en ligne). */
function lwbd_pay_link($invoiceId)
{
    $lib = ROOTDIR . '/modules/gateways/flexpay/lib/Gateway.php';
    try {
        if (is_file($lib)) {
            require_once $lib;
            if (class_exists('\\FlexPayWhmcs\\Gateway') && \FlexPayWhmcs\Gateway::isActive()) {
                return \FlexPayWhmcs\Gateway::guestPayUrl((int) $invoiceId, 2);
            }
        }
    } catch (\Throwable $e) {
    }
    return rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/viewinvoice.php?id=' . (int) $invoiceId;
}

/**
 * Message WhatsApp prêt à envoyer pour une facture impayée.
 *
 * @return array|null ['phone', 'url', 'text', 'link', 'client']
 */
function lwbd_wa_invoice($invoiceId)
{
    $inv = Capsule::table('tblinvoices as i')->join('tblclients as c', 'c.id', '=', 'i.userid')
        ->where('i.id', (int) $invoiceId)
        ->first(['i.id', 'i.invoicenum', 'i.duedate', 'i.status', 'i.total', 'c.firstname', 'c.lastname', 'c.companyname', 'c.phonenumber', 'c.country']);
    if (!$inv) {
        return null;
    }
    $paid = (float) Capsule::table('tblaccounts')->where('invoiceid', $inv->id)->sum(Capsule::raw('amountin - amountout'));
    $balance = max(0, (float) $inv->total - $paid);
    $late = lwbd_days_between($inv->duedate, date('Y-m-d'));
    $link = lwbd_pay_link($inv->id);
    $num = $inv->invoicenum ?: $inv->id;
    $when = $late > 0 ? 'est en retard de ' . $late . ' jour' . ($late > 1 ? 's' : '') : ($late === 0 ? "arrive à échéance aujourd'hui" : 'arrive à échéance le ' . date('d/m/Y', strtotime($inv->duedate)));
    $text = 'Bonjour ' . trim($inv->firstname) . ",\n"
        . 'Votre facture LEWEBMAX n° ' . $num . ' de ' . number_format($balance, 2, ',', ' ') . ' $ ' . $when . ".\n"
        . "Vous pouvez la régler en 1 minute par Mobile Money (M-Pesa, Orange Money, Airtel Money) :\n" . $link . "\n"
        . 'Merci, l\'équipe LEWEBMAX';
    $phone = lwbd_wa_phone($inv->phonenumber, $inv->country);
    return [
        'phone' => $phone,
        'url' => 'https://wa.me/' . $phone . '?text=' . rawurlencode($text),
        'text' => $text,
        'link' => $link,
        'client' => lwbd_client_name($inv),
        'balance' => $balance,
    ];
}

/**
 * Toute la file « À faire », par groupe.
 *
 * @param bool $countOnly ne calcule que le nombre (pour le compteur du menu)
 */
function lwbd_todo_items($countOnly = false)
{
    $snoozed = lwbd_snoozed();
    $today = date('Y-m-d');
    $groups = [];
    $add = function ($group, $key, array $item) use (&$groups, $snoozed) {
        if (isset($snoozed[$key])) {
            return;
        }
        $groups[$group][] = $item + ['key' => $key];
    };

    // Système : cron arrêté, registraire sans crédit.
    $h = lwbd_health();
    if (!$h['cron']['ok']) {
        $add('system', 'sys:cron', ['level' => 'critical', 'title' => 'Le cron WHMCS ne tourne plus', 'meta' => 'Dernier passage ' . lwbd_ago($h['cron']['minutes']) . ' : factures, relances et renouvellements sont à l\'arrêt.', 'href' => 'automationstatus.php']);
    }
    if ($h['registrar']['no_credit']) {
        $add('system', 'sys:credit', ['level' => 'critical', 'title' => 'Crédit Porkbun épuisé', 'meta' => 'Les enregistrements et renouvellements de domaines échouent. Rechargez le compte puis relancez les domaines concernés.', 'href' => 'https://porkbun.com/account/credit', 'external' => true]);
    }

    // Commandes à accepter.
    foreach (Capsule::table('tblorders as o')->join('tblclients as c', 'c.id', '=', 'o.userid')
        ->leftJoin('tblinvoices as i', 'i.id', '=', 'o.invoiceid')->where('o.status', 'Pending')->orderBy('o.date')
        ->get(['o.id', 'o.ordernum', 'o.date', 'o.amount', 'o.userid', 'i.status as inv', 'c.firstname', 'c.lastname', 'c.companyname']) as $o) {
        $paid = $o->inv === 'Paid' || (float) $o->amount == 0;
        $add('orders', 'order:' . $o->id, ['level' => $paid ? 'warning' : 'info', 'id' => (int) $o->id, 'userid' => (int) $o->userid,
            'title' => 'Commande n° ' . $o->ordernum . ' · ' . lwbd_client_name($o), 'meta' => lwbd_money($o->amount) . ' · ' . lwbd_date($o->date) . ' · ' . ($paid ? 'payée, à valider' : 'en attente de paiement'),
            'href' => 'orders.php?action=view&id=' . $o->id, 'paid' => $paid]);
    }

    // Services payés mais toujours « En attente ».
    foreach ($h['paid_pending'] as $s) {
        $add('services', 'svc:' . $s->id, ['level' => 'warning', 'id' => (int) $s->id, 'userid' => (int) $s->userid,
            'title' => $s->name . ($s->domain ? ' · ' . $s->domain : ''), 'meta' => 'Payé, mais pas encore activé', 'href' => 'clientsservices.php?userid=' . $s->userid . '&id=' . $s->id]);
    }

    // Tickets en attente de réponse.
    $tq = Capsule::table('tbltickets as t')->leftJoin('tblclients as c', 'c.id', '=', 't.userid')->whereIn('t.status', ['Open', 'Customer-Reply']);
    if (Capsule::schema()->hasColumn('tbltickets', 'merged_ticket_id')) {
        $tq->where('t.merged_ticket_id', 0);
    }
    foreach ($tq->orderBy('t.lastreply')->get(['t.id', 't.tid', 't.title', 't.status', 't.lastreply', 't.userid', 't.name', 'c.firstname', 'c.lastname', 'c.companyname']) as $t) {
        $age = lwbd_days_between($t->lastreply, $today);
        $add('tickets', 'ticket:' . $t->id . ':' . substr((string) $t->lastreply, 0, 16), ['level' => $age >= 2 ? 'serious' : 'info', 'id' => (int) $t->id,
            'title' => '#' . $t->tid . ' ' . $t->title, 'meta' => ($t->userid ? lwbd_client_name($t) : ($t->name ?: 'Visiteur')) . ' · ' . ($age === 0 ? "aujourd'hui" : 'depuis ' . $age . ' j'),
            'href' => 'supporttickets.php?action=view&id=' . $t->id]);
    }

    // Factures dues sous 3 jours ou en retard depuis moins de 30 jours.
    foreach (lwbd_unpaid_invoices() as $inv) {
        $balance = round((float) $inv->balance, 2);
        $late = lwbd_days_between($inv->duedate, $today);
        if ($balance <= 0 || $late < -3 || $late > 30) {
            continue;
        }
        $add('invoices', 'inv:' . $inv->id, ['level' => $late > 4 ? 'critical' : ($late > 0 ? 'serious' : 'warning'), 'id' => (int) $inv->id, 'userid' => (int) $inv->userid,
            'title' => 'Facture n° ' . ($inv->invoicenum ?: $inv->id) . ' · ' . lwbd_client_name($inv),
            'meta' => lwbd_money($balance) . ' · ' . ($late > 0 ? 'en retard de ' . $late . ' j' : ($late === 0 ? "échéance aujourd'hui" : 'échéance dans ' . (-$late) . ' j')),
            'href' => 'invoices.php?action=edit&id=' . $inv->id, 'late' => $late]);
    }

    // Domaines en danger.
    foreach (Capsule::table('tbldomains as d')->join('tblclients as c', 'c.id', '=', 'd.userid')
        ->whereIn('d.status', ['Active', 'Grace', 'Redemption', 'Pending'])
        ->get(['d.id', 'd.userid', 'd.domain', 'd.status', 'd.expirydate', 'd.nextduedate', 'c.firstname', 'c.lastname', 'c.companyname']) as $x) {
        $reason = null;
        if ($x->status === 'Grace' || $x->status === 'Redemption') {
            $reason = $x->status === 'Grace' ? 'En période de grâce' : 'En rédemption (frais de restauration)';
        } elseif (lwbd_date_ok($x->expirydate) && lwbd_date_ok($x->nextduedate) && lwbd_days_between($x->expirydate, $x->nextduedate) > 45
            && lwbd_days_between($today, $x->expirydate) <= 30) {
            $reason = 'Payé mais pas renouvelé chez le registraire';
        }
        if ($reason) {
            $add('domains', 'dom:' . $x->id, ['level' => 'critical', 'id' => (int) $x->id, 'userid' => (int) $x->userid,
                'title' => $x->domain . ' · ' . lwbd_client_name($x), 'meta' => $reason . (lwbd_date_ok($x->expirydate) ? ' · expiré le ' . lwbd_date($x->expirydate) : ''),
                'href' => 'clientsdomains.php?userid=' . $x->userid . '&id=' . $x->id, 'status' => $x->status]);
        }
    }

    // Actions de modules en échec et demandes de résiliation.
    if (Capsule::schema()->hasTable('tblmodulequeue')) {
        foreach (Capsule::table('tblmodulequeue')->where('completed', 0)->orderBy('id', 'desc')->limit(20)->get(['id', 'service_type', 'service_id', 'module_name', 'module_action', 'last_attempt_error']) as $q) {
            $add('modules', 'mq:' . $q->id, ['level' => 'serious', 'title' => ucfirst((string) $q->module_name) . ' · ' . $q->module_action . ' (' . $q->service_type . ' #' . $q->service_id . ')',
                'meta' => lwbd_log_excerpt((string) $q->last_attempt_error, 140), 'href' => 'modulequeue.php']);
        }
    }
    foreach ($h['cancels'] as $c) {
        $add('cancels', 'cancel:' . $c->id . ':' . substr((string) $c->date, 0, 10), ['level' => 'warning', 'title' => $c->name . ($c->domain ? ' · ' . $c->domain : ''),
            'meta' => ($c->type === 'Immediate' ? 'Immédiate' : 'Fin de période') . ' · ' . lwbd_log_excerpt((string) $c->reason, 90), 'href' => 'cancelrequests.php']);
    }

    $count = array_sum(array_map('count', $groups));
    return $countOnly ? $count : ['groups' => $groups, 'count' => $count, 'snoozed' => count($snoozed)];
}

/** Libellés et icônes des groupes, dans l'ordre d'affichage. */
function lwbd_todo_groups()
{
    return [
        'system' => ['Urgences système', 'alert'],
        'orders' => ['Commandes à traiter', 'cart'],
        'services' => ['Services à activer', 'server'],
        'tickets' => ['Tickets à répondre', 'chat'],
        'invoices' => ['Factures à encaisser', 'cash'],
        'domains' => ['Domaines en danger', 'globe'],
        'modules' => ['Actions de modules en échec', 'tool'],
        'cancels' => ['Demandes de résiliation', 'repeat'],
    ];
}

/** Petit formulaire d'action protégé par le jeton CSRF. */
function lwbd_todo_form($link, $token, $action, array $fields, $label, $class = '', $confirm = '')
{
    $html = '<form method="post" action="' . lwbd_e($link) . '" class="lwbd-act"' . ($confirm !== '' ? ' onsubmit="return confirm(\'' . lwbd_e(addslashes($confirm)) . '\')"' : '') . '>'
        . $token . '<input type="hidden" name="lwbd_action" value="' . lwbd_e($action) . '">';
    foreach ($fields as $k => $v) {
        $html .= '<input type="hidden" name="' . lwbd_e($k) . '" value="' . lwbd_e($v) . '">';
    }
    return $html . '<button type="submit" class="lwbd-act-btn ' . $class . '">' . $label . '</button></form>';
}

function lwbd_todo_render(array $todo, $modulelink, $token, $flash = null)
{
    $link = $modulelink . '&view=todo';
    $groups = lwbd_todo_groups();
    $msg = '';
    if ($flash) {
        $level = ['success' => 'good', 'warning' => 'warning', 'danger' => 'critical'][$flash[0]] ?? 'info';
        $msg = '<div class="lwbd-flash lwbd-' . $level . '" role="status">' . lwbd_e($flash[1]) . '</div>';
    }
    $chips = '<nav class="lwbd-todo-chips">';
    foreach ($groups as $g => [$label, $icon]) {
        $n = count($todo['groups'][$g] ?? []);
        if ($n) {
            $chips .= '<a href="#todo-' . $g . '">' . lwbd_icon($icon) . lwbd_e($label) . ' <b>' . $n . '</b></a>';
        }
    }
    $chips .= '</nav>';
    $body = '';
    foreach ($groups as $g => [$label, $icon]) {
        $items = $todo['groups'][$g] ?? [];
        if (!$items) {
            continue;
        }
        $rows = '';
        foreach ($items as $it) {
            $acts = '';
            $snooze = lwbd_todo_form($link, $token, 'snooze', ['item' => $it['key'], 'days' => 1], 'Demain', 'ghost')
                . lwbd_todo_form($link, $token, 'snooze', ['item' => $it['key'], 'days' => 7], '7 j', 'ghost');
            $open = '<a class="lwbd-act-btn" href="' . lwbd_e($it['href']) . '"' . (!empty($it['external']) ? ' target="_blank" rel="noopener"' : '') . '>Ouvrir</a>';
            switch ($g) {
                case 'orders':
                    $acts = lwbd_todo_form($link, $token, 'accept_order', ['order' => $it['id']], 'Accepter', 'primary', 'Accepter la commande et lancer l\'activation des services ?') . $open;
                    break;
                case 'services':
                    $acts = lwbd_todo_form($link, $token, 'activate_service', ['service' => $it['id']], 'Activer', 'primary', 'Créer et activer ce service maintenant ?') . $open;
                    break;
                case 'tickets':
                    $acts = '<a class="lwbd-act-btn primary" href="' . lwbd_e($it['href']) . '#replymessage">Répondre</a>';
                    break;
                case 'invoices':
                    $wa = lwbd_wa_invoice($it['id']);
                    $acts = lwbd_todo_form($link, $token, 'remind', ['invoice' => $it['id']], 'Relancer par e-mail', 'primary', 'Envoyer un e-mail de relance au client ?');
                    if ($wa && $wa['phone']) {
                        $acts .= '<a class="lwbd-act-btn wa" href="' . lwbd_e($wa['url']) . '" target="_blank" rel="noopener">WhatsApp</a>';
                    }
                    if ($wa) {
                        $acts .= '<button type="button" class="lwbd-act-btn" data-copy="' . lwbd_e($wa['link']) . '">Copier le lien</button>';
                    }
                    $acts .= $open;
                    break;
                case 'domains':
                    $acts = lwbd_todo_form($link, $token, 'domain_notice', ['domain' => $it['id']], 'Prévenir le client', 'primary', 'Envoyer au client l\'avis de renouvellement de ce domaine ?') . $open;
                    break;
                default:
                    $acts = $open;
            }
            $rows .= '<li class="lwbd-todo-item lvl-' . lwbd_e($it['level']) . '"><div class="lwbd-todo-main"><b>' . lwbd_e($it['title']) . '</b><small>' . lwbd_e($it['meta']) . '</small></div>'
                . '<div class="lwbd-todo-acts">' . $acts . '<span class="lwbd-todo-snooze" title="Reporter">' . $snooze . '</span></div></li>';
        }
        $body .= '<section class="lwbd-card lwbd-todo-group" id="todo-' . $g . '"><header class="lwbd-card-h"><h2>' . lwbd_icon($icon) . ' ' . lwbd_e($label) . '</h2><span class="lwbd-count">' . count($items) . '</span></header><ul class="lwbd-todo-list">' . $rows . '</ul></section>';
    }
    if ($body === '') {
        $body = '<section class="lwbd-card lwbd-todo-empty"><p class="lwbd-todo-done">' . lwbd_icon('check') . '</p><h2>Tout est à jour</h2><p>Aucune commande, aucun ticket, aucune facture ni aucun domaine n\'attend d\'action de votre part.</p></section>';
    }
    $html = '<div class="lwbd lwbd-todo" id="lwbd">'
        . '<div class="lwbd-head"><div><p class="lwbd-eyebrow"><a href="' . lwbd_e($modulelink) . '">' . lwbd_icon('back') . 'Tableau de bord</a></p><h1>À faire <span class="lwbd-count big">' . (int) $todo['count'] . '</span></h1>'
        . '<p class="lwbd-todo-sub">Tout ce qui attend une action de votre part, du plus urgent au moins urgent.' . ($todo['snoozed'] ? ' ' . (int) $todo['snoozed'] . ' élément(s) reporté(s).' : '') . '</p></div></div>'
        . $msg . $chips . '<div class="lwbd-todo-groups">' . $body . '</div><div class="lwbd-tip" role="tooltip" hidden></div></div>';
    return lwbd_style() . $html . lwbd_script();
}
