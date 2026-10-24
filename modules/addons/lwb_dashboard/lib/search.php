<?php
/**
 * Lwb-dashboard — recherche rapide (Ctrl+K) : réglages WHMCS, clients, factures,
 * domaines, services, tickets et commandes.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

/** Menu « Configuration » du rail : tous les réglages WHMCS, groupés (WHMCS vérifie les droits sur chaque page). */
function lwbd_setup_menu()
{
    $r = function ($name, $fallback) {
        try {
            return function_exists('routePath') ? (string) routePath($name) : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    };
    return [
        ['Réglages', [
            ['Paramètres système', $r('admin-setup-index', 'configgeneral.php')],
            ['Paramètres généraux', 'configgeneral.php'],
            ['Applications et intégrations', $r('admin-apps-index', 'configaddonmods.php')],
            ['Intégrations de connexion', $r('admin-setup-authn-view', 'configgeneral.php')],
            ['Automatisation', 'configauto.php'],
            ['MarketConnect', 'marketconnect.php'],
            ['Notifications', $r('admin-setup-notifications-overview', 'configgeneral.php')],
            ['Stockage', $r('admin-setup-storage-index', 'configgeneral.php')],
            ['Liens d\'application (cPanel)', 'configapplinks.php'],
            ['OpenID Connect', 'configopenid.php'],
            ['Modèles d\'e-mails', 'configemailtemplates.php'],
            ['Modules complémentaires', 'configaddonmods.php'],
            ['Groupes de clients', 'configclientgroups.php'],
            ['Champs personnalisés', 'configcustomfields.php'],
            ['Anti-fraude', 'configfraud.php'],
        ]],
        ['Équipe', [
            ['Administrateurs', 'configadmins.php'],
            ['Rôles', 'configadminroles.php'],
            ['Double authentification', 'configtwofa.php'],
            ['Identifiants API', 'configapicredentials.php'],
        ]],
        ['Paiements', [
            ['Devises', 'configcurrencies.php'],
            ['Passerelles de paiement', 'configgateways.php'],
            ['Taxes', $r('admin-setup-payments-tax-index', 'configtax.php')],
            ['Promotions', 'configpromotions.php'],
        ]],
        ['Produits', [
            ['Produits et services', 'configproducts.php'],
            ['Options configurables', 'configproductoptions.php'],
            ['Options de produits (addons)', 'configaddons.php'],
            ['Packs', 'configbundles.php'],
            ['Tarifs des domaines', 'configdomains.php'],
            ['Registrars', 'configregistrars.php'],
            ['Serveurs', 'configservers.php'],
        ]],
        ['Support', [
            ['Départements', 'configticketdepartments.php'],
            ['Statuts des tickets', 'configticketstatuses.php'],
            ['Règles d\'escalade', 'configticketescalations.php'],
            ['Anti-spam', 'configticketspamcontrol.php'],
        ]],
        ['Autres', [
            ['Statuts des commandes', 'configorderstatuses.php'],
            ['Questions de sécurité', 'configsecurityqs.php'],
            ['IP bannies', 'configbannedips.php'],
            ['E-mails bannis', 'configbannedemails.php'],
            ['Sauvegardes', 'configbackups.php'],
            ['Santé et mises à jour', 'systemhealthandupdates.php'],
            ['Assistant de configuration', $r('admin-getting-started-index', 'configgeneral.php')],
        ]],
    ];
}

/** Minuscules sans accents, pour comparer « reglages » et « Réglages ». */
function lwbd_fold($s)
{
    $s = mb_strtolower(trim((string) $s), 'UTF-8');
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return preg_replace('/[^a-z0-9@.+ -]/', '', $t !== false ? $t : $s);
}

/**
 * Réglages correspondant à la recherche (tous les mots doivent apparaître).
 *
 * @param array $menu structure de lwbd_setup_menu()
 * @return array [['label', 'href', 'group']]
 */
function lwbd_search_menu($q, array $menu, $limit = 6)
{
    $words = array_filter(explode(' ', lwbd_fold($q)), 'strlen');
    if (!$words) {
        return [];
    }
    $out = [];
    foreach ($menu as [$group, $items]) {
        foreach ($items as [$label, $href]) {
            $hay = lwbd_fold($label . ' ' . $group . ' ' . $href);
            $ok = true;
            foreach ($words as $w) {
                if (strpos($hay, $w) === false) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                $out[] = ['label' => $label, 'href' => $href, 'group' => $group, 'score' => strpos(lwbd_fold($label), $words[array_key_first($words)]) === 0 ? 0 : 1];
            }
        }
    }
    usort($out, function ($a, $b) {
        return $a['score'] <=> $b['score'];
    });
    return array_map(function ($r) {
        unset($r['score']);
        return $r;
    }, array_slice($out, 0, $limit));
}

/**
 * Résultats groupés pour la palette.
 *
 * @return array [['type', 'label', 'meta', 'href']]
 */
function lwbd_search($q)
{
    $q = trim((string) $q);
    if (mb_strlen($q) < 2) {
        return [];
    }
    $out = [];
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    $num = preg_replace('/\D/', '', $q);
    $isNum = $num !== '' && preg_match('/^\s*#?\s*\d+\s*$/', $q);
    foreach (lwbd_search_menu($q, lwbd_setup_menu()) as $r) {
        $out[] = ['type' => 'Réglage', 'label' => $r['label'], 'meta' => $r['group'], 'href' => $r['href']];
    }
    $clients = Capsule::table('tblclients')->where(function ($w) use ($like, $isNum, $num) {
        $w->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like)->orWhere('companyname', 'like', $like)
            ->orWhere('email', 'like', $like)->orWhereRaw("CONCAT(firstname, ' ', lastname) LIKE ?", [$like]);
        if ($isNum) {
            $w->orWhere('id', (int) $num)->orWhere('phonenumber', 'like', '%' . $num . '%');
        }
    })->orderBy('status')->limit(6)->get(['id', 'firstname', 'lastname', 'companyname', 'email', 'status']);
    foreach ($clients as $c) {
        $out[] = ['type' => 'Client', 'label' => lwbd_client_name($c) . ' #' . $c->id, 'meta' => $c->email . ($c->status !== 'Active' ? ' · ' . $c->status : ''), 'href' => 'clientssummary.php?userid=' . $c->id];
    }
    if ($isNum) {
        foreach (Capsule::table('tblinvoices as i')->join('tblclients as c', 'c.id', '=', 'i.userid')
            ->where('i.id', (int) $num)->orWhere('i.invoicenum', $num)->limit(3)
            ->get(['i.id', 'i.invoicenum', 'i.status', 'i.total', 'i.duedate', 'c.firstname', 'c.lastname', 'c.companyname']) as $i) {
            $out[] = ['type' => 'Facture', 'label' => 'Facture n° ' . ($i->invoicenum ?: $i->id), 'meta' => lwbd_client_name($i) . ' · ' . lwbd_money($i->total) . ' · ' . $i->status, 'href' => 'invoices.php?action=edit&id=' . $i->id];
        }
        foreach (Capsule::table('tblorders as o')->join('tblclients as c', 'c.id', '=', 'o.userid')
            ->where('o.id', (int) $num)->orWhere('o.ordernum', $num)->limit(2)
            ->get(['o.id', 'o.ordernum', 'o.status', 'o.amount', 'c.firstname', 'c.lastname', 'c.companyname']) as $o) {
            $out[] = ['type' => 'Commande', 'label' => 'Commande n° ' . $o->ordernum, 'meta' => lwbd_client_name($o) . ' · ' . lwbd_money($o->amount) . ' · ' . $o->status, 'href' => 'orders.php?action=view&id=' . $o->id];
        }
    }
    foreach (Capsule::table('tbldomains')->where('domain', 'like', $like)->limit(5)->get(['id', 'userid', 'domain', 'status', 'expirydate']) as $d) {
        $out[] = ['type' => 'Domaine', 'label' => $d->domain, 'meta' => $d->status . (lwbd_date_ok($d->expirydate) ? ' · expire le ' . lwbd_date($d->expirydate) : ''), 'href' => 'clientsdomains.php?userid=' . $d->userid . '&id=' . $d->id];
    }
    foreach (Capsule::table('tblhosting as h')->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where(function ($w) use ($like) {
            $w->where('h.domain', 'like', $like)->orWhere('h.username', 'like', $like)->orWhere('h.dedicatedip', 'like', $like);
        })->limit(5)->get(['h.id', 'h.userid', 'h.domain', 'h.domainstatus', 'p.name']) as $h) {
        $out[] = ['type' => 'Service', 'label' => $h->name . ($h->domain ? ' · ' . $h->domain : ''), 'meta' => $h->domainstatus, 'href' => 'clientsservices.php?userid=' . $h->userid . '&id=' . $h->id];
    }
    $tq = Capsule::table('tbltickets')->where(function ($w) use ($like, $q) {
        $w->where('title', 'like', $like)->orWhere('tid', ltrim($q, '#'));
    });
    foreach ($tq->orderBy('id', 'desc')->limit(5)->get(['id', 'tid', 'title', 'status']) as $t) {
        $out[] = ['type' => 'Ticket', 'label' => '#' . $t->tid . ' ' . $t->title, 'meta' => $t->status, 'href' => 'supporttickets.php?action=view&id=' . $t->id];
    }
    return array_slice($out, 0, 30);
}
