<?php
/**
 * Lwb-dashboard — surveillance de ce qui peut tomber en panne sans prévenir :
 * passerelle WhatsApp, file d'e-mails, certificat SSL, sauvegardes, espace disque.
 *
 * Les contrôles coûteux (SSL, disque, sauvegardes) sont mis en cache ; les alertes
 * partent sur le WhatsApp de l'équipe (module lwb-whmcs-wha) ou, à défaut, par e-mail
 * aux administrateurs, une seule fois par problème et par jour, avec un message
 * « Résolu » quand le problème disparaît.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

const LWBD_STATE_TABLE = 'mod_lwbd_state';

function lwbd_state_table()
{
    static $done = false;
    if (!$done) {
        if (!Capsule::schema()->hasTable(LWBD_STATE_TABLE)) {
            Capsule::schema()->create(LWBD_STATE_TABLE, function ($t) {
                $t->string('k', 64)->primary();
                $t->mediumText('v')->nullable();
                $t->dateTime('updated_at')->nullable();
            });
        }
        $done = true;
    }
}

/** Valeur mémorisée (JSON) ou $default. */
function lwbd_state_get($key, $default = null)
{
    try {
        lwbd_state_table();
        $v = Capsule::table(LWBD_STATE_TABLE)->where('k', $key)->value('v');
        return $v === null ? $default : json_decode($v, true);
    } catch (\Throwable $e) {
        return $default;
    }
}

function lwbd_state_set($key, $value)
{
    try {
        lwbd_state_table();
        Capsule::table(LWBD_STATE_TABLE)->updateOrInsert(['k' => $key], ['v' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
    }
}

/** Résultat mis en cache $ttl secondes (recalculé si $refresh). */
function lwbd_cached($key, $ttl, callable $fn, $refresh = false)
{
    $c = lwbd_state_get('cache:' . $key);
    if (!$refresh && is_array($c) && isset($c['t']) && $c['t'] > time() - $ttl) {
        return $c['v'];
    }
    try {
        $v = $fn();
    } catch (\Throwable $e) {
        $v = null;
    }
    lwbd_state_set('cache:' . $key, ['t' => time(), 'v' => $v]);
    return $v;
}

// ------------------------------------------------------------------ niveaux (fonctions pures)

/** Niveau d'alerte du certificat selon les jours restants (null = inconnu). */
function lwbd_cert_level($days)
{
    if ($days === null) {
        return 'info';
    }
    return $days <= 7 ? 'critical' : ($days <= 21 ? 'warning' : 'good');
}

/** Niveau d'alerte de la dernière sauvegarde selon son âge en heures (null = aucune). */
function lwbd_backup_level($hours)
{
    if ($hours === null) {
        return 'warning';
    }
    return $hours > 50 ? 'critical' : ($hours > 30 ? 'serious' : 'good');
}

/** Niveau d'alerte de l'espace disque selon le pourcentage utilisé. */
function lwbd_disk_level($pct)
{
    if ($pct === null) {
        return 'info';
    }
    return $pct >= 90 ? 'critical' : ($pct >= 80 ? 'warning' : 'good');
}

function lwbd_bytes($b)
{
    $u = ['o', 'Ko', 'Mo', 'Go', 'To'];
    $i = 0;
    $b = (float) $b;
    while ($b >= 1024 && $i < count($u) - 1) {
        $b /= 1024;
        $i++;
    }
    return number_format($b, $i >= 3 ? 1 : 0, ',', ' ') . ' ' . $u[$i];
}

// ------------------------------------------------------------------ contrôles

/** Le module WhatsApp lwb-whmcs-wha est-il installé et actif ? */
function lwbd_wha_active()
{
    static $on = null;
    if ($on === null) {
        try {
            $on = class_exists('\LwbWha\Settings') && class_exists('\LwbWha\Gateway')
                && Capsule::table('tbladdonmodules')->where('module', 'lwb_whmcs_wha')->where('setting', 'version')->exists();
        } catch (\Throwable $e) {
            $on = false;
        }
    }
    return $on;
}

/** Passerelle WhatsApp : état, messages en échec et en attente. */
function lwbd_check_whatsapp()
{
    if (!lwbd_wha_active()) {
        return null;
    }
    $gateway = \LwbWha\Settings::isGateway();
    $status = $gateway ? lwbd_cached('wa_status', 60, function () {
        return \LwbWha\Gateway::status(true);
    }) : ['status' => 'cloud'];
    $open = !$gateway || ($status['status'] ?? '') === 'open';
    // La passerelle redémarre seule en moins de 5 minutes : on ne s'alarme qu'après 10 minutes.
    $since = lwbd_state_get('wa_down_since');
    if ($open && $since) {
        lwbd_state_set('wa_down_since', null);
        $since = null;
    } elseif (!$open && !$since) {
        $since = time();
        lwbd_state_set('wa_down_since', $since);
    }
    $table = 'mod_lwbwha_messages';
    $failed = Capsule::table($table)->where('status', 'failed')->where('created_at', '>=', date('Y-m-d H:i:s', time() - 86400))->count();
    $pending = Capsule::table($table)->where('status', 'pending')->count();
    $old = Capsule::table($table)->where('status', 'pending')->where('created_at', '<', date('Y-m-d H:i:s', time() - 6 * 3600))->count();
    $down = !$open ? (int) floor((time() - (int) $since) / 60) : 0;
    if (!$open) {
        $level = $down >= 10 ? 'critical' : 'warning';
        $text = 'Déconnectée depuis ' . max(1, $down) . ' min' . ($pending ? ' · ' . $pending . ' message(s) en attente' : '') . '. Les notifications clients ne partent plus.';
        if (($status['status'] ?? '') === 'qr') {
            $text = 'Session WhatsApp à rescanner (QR code) : ouvrez l\'onglet Connexion du module WhatsApp.';
            $level = 'critical';
        }
    } elseif ($failed) {
        $level = 'serious';
        $text = $failed . ' message(s) en échec sur 24 h' . ($pending ? ' · ' . $pending . ' en attente' : '');
    } elseif ($old) {
        $level = 'warning';
        $text = $old . ' message(s) en attente depuis plus de 6 h';
    } else {
        $level = 'good';
        $text = $gateway ? 'Connectée' . ($pending ? ' · ' . $pending . ' message(s) en file' : '') : 'WhatsApp Cloud API';
    }
    return ['key' => 'mon:whatsapp', 'level' => $level, 'title' => 'Passerelle WhatsApp', 'text' => $text,
        'href' => 'addonmodules.php?module=lwb_whmcs_wha', 'alert' => !$open ? $down >= 10 : $level === 'serious'];
}

/** File d'e-mails WHMCS : envois échoués ou bloqués. */
function lwbd_check_emails()
{
    $since = date('Y-m-d H:i:s', time() - 86400);
    $failed = Capsule::schema()->hasColumn('tblemails', 'failed')
        ? Capsule::table('tblemails')->where('date', '>=', $since)->where('failed', 1)->orderBy('id', 'desc')->get(['subject', 'failure_reason'])->all()
        : [];
    $stuck = Capsule::schema()->hasColumn('tblemails', 'pending')
        ? Capsule::table('tblemails')->where('pending', 1)->where('date', '<', date('Y-m-d H:i:s', time() - 3600))->count()
        : 0;
    $sent = Capsule::table('tblemails')->where('date', '>=', date('Y-m-d'))->count();
    if ($failed) {
        $reason = lwbd_log_excerpt((string) $failed[0]->failure_reason, 110);
        return ['key' => 'mon:emails', 'level' => 'serious', 'title' => 'File d\'e-mails', 'text' => count($failed) . ' e-mail(s) en échec sur 24 h' . ($reason !== '' ? ' : ' . $reason : ''),
            'href' => 'systememaillog.php', 'alert' => count($failed) >= 3];
    }
    if ($stuck) {
        return ['key' => 'mon:emails', 'level' => 'warning', 'title' => 'File d\'e-mails', 'text' => $stuck . ' e-mail(s) en attente depuis plus d\'une heure', 'href' => 'systememaillog.php', 'alert' => false];
    }
    return ['key' => 'mon:emails', 'level' => 'good', 'title' => 'File d\'e-mails', 'text' => $sent . ' e-mail(s) envoyé(s) aujourd\'hui, aucun échec', 'href' => 'systememaillog.php', 'alert' => false];
}

/** Jours avant expiration du certificat SSL de l'adresse WHMCS (null = vérification impossible). */
function lwbd_ssl_days($host)
{
    if ($host === '' || !function_exists('stream_socket_client')) {
        return null;
    }
    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $fp = @stream_socket_client('ssl://' . $host . ':443', $errno, $errstr, 6, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        return null;
    }
    $params = stream_context_get_params($fp);
    fclose($fp);
    $cert = isset($params['options']['ssl']['peer_certificate']) ? openssl_x509_parse($params['options']['ssl']['peer_certificate']) : null;
    if (!$cert || empty($cert['validTo_time_t'])) {
        return null;
    }
    return (int) floor(($cert['validTo_time_t'] - time()) / 86400);
}

function lwbd_check_ssl($refresh = false)
{
    $host = (string) parse_url((string) \WHMCS\Config\Setting::getValue('SystemURL'), PHP_URL_HOST);
    $days = lwbd_cached('ssl:' . $host, 12 * 3600, function () use ($host) {
        return lwbd_ssl_days($host);
    }, $refresh);
    $level = lwbd_cert_level($days);
    $text = $days === null ? 'Vérification impossible pour ' . $host
        : ($days < 0 ? 'Expiré depuis ' . abs($days) . ' jour(s) : clients et paiements bloqués par le navigateur' : 'Valide encore ' . $days . ' jour(s) (' . $host . ')');
    return ['key' => 'mon:ssl', 'level' => $level, 'title' => 'Certificat SSL', 'text' => $text, 'href' => 'https://' . $host, 'alert' => $level === 'critical'];
}

/** Dossier des sauvegardes : réglage, sinon emplacements courants à côté de WHMCS. */
function lwbd_backup_dirs()
{
    $set = trim((string) (lwbd_settings()['backup_dir'] ?? ''));
    if ($set !== '') {
        return [$set];
    }
    $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 4);
    return [dirname($root) . '/whmcs_backups', dirname($root) . '/backups', $root . '/backups', dirname($root) . '/backup'];
}

/** Dernière sauvegarde trouvée : ['file', 'time', 'size'] ou null. */
function lwbd_latest_backup()
{
    $best = null;
    foreach (lwbd_backup_dirs() as $dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            continue;
        }
        foreach (glob(rtrim($dir, '/') . '/*.{gz,sql,zip,tar,tgz,bz2,xz}', GLOB_BRACE) ?: [] as $f) {
            $t = @filemtime($f);
            if ($t && (!$best || $t > $best['time'])) {
                $best = ['file' => $f, 'time' => $t, 'size' => (int) @filesize($f)];
            }
        }
    }
    return $best;
}

function lwbd_check_backup()
{
    $b = lwbd_latest_backup();
    $hours = $b ? (int) floor((time() - $b['time']) / 3600) : null;
    $level = lwbd_backup_level($hours);
    // Une sauvegarde de quelques octets est un échec déguisé.
    if ($b && $b['size'] < 10240) {
        $level = 'critical';
    }
    $text = !$b ? 'Aucune sauvegarde trouvée (dossier à indiquer dans les réglages du tableau de bord)'
        : 'Dernière ' . lwbd_ago((int) floor((time() - $b['time']) / 60)) . ' · ' . lwbd_bytes($b['size']) . ' · ' . basename($b['file']);
    return ['key' => 'mon:backup', 'level' => $level, 'title' => 'Sauvegarde de la base', 'text' => $text, 'href' => 'configbackups.php', 'alert' => in_array($level, ['critical', 'serious'], true)];
}

/** Espace disque du compte : quota cPanel (uapi) si disponible, sinon le disque du serveur. */
function lwbd_disk_usage()
{
    if (function_exists('proc_open') && is_executable('/usr/bin/uapi')) {
        $p = @proc_open(['/usr/bin/uapi', 'Quota', 'get_quota_info', '--output=json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (is_resource($p)) {
            $out = stream_get_contents($pipes[1]);
            proc_close($p);
            $data = json_decode((string) $out, true)['result']['data'] ?? null;
            if (is_array($data) && (float) ($data['megabyte_limit'] ?? 0) > 0) {
                return ['used' => (float) $data['megabytes_used'] * 1048576, 'total' => (float) $data['megabyte_limit'] * 1048576, 'source' => 'quota'];
            }
        }
    }
    $root = defined('ROOTDIR') ? ROOTDIR : __DIR__;
    $total = @disk_total_space($root);
    $free = @disk_free_space($root);
    return $total ? ['used' => $total - $free, 'total' => $total, 'source' => 'disk'] : null;
}

function lwbd_check_disk($refresh = false)
{
    $u = lwbd_cached('disk', 6 * 3600, 'lwbd_disk_usage', $refresh);
    $pct = $u ? round($u['used'] / $u['total'] * 100) : null;
    $level = lwbd_disk_level($pct);
    $text = $u ? $pct . ' % utilisé · ' . lwbd_bytes($u['used']) . ' sur ' . lwbd_bytes($u['total']) . ($u['source'] === 'quota' ? ' (quota du compte)' : '') : 'Information indisponible';
    return ['key' => 'mon:disk', 'level' => $level, 'title' => 'Espace disque', 'text' => $text, 'href' => 'systemhealthandupdates.php', 'alert' => $level === 'critical'];
}

/**
 * Tous les contrôles de surveillance, du plus grave au moins grave.
 *
 * @return array liste de ['key', 'level', 'title', 'text', 'href', 'alert']
 */
function lwbd_monitor($refresh = false)
{
    $items = [];
    foreach (['lwbd_check_whatsapp', 'lwbd_check_emails', 'lwbd_check_ssl', 'lwbd_check_backup', 'lwbd_check_disk'] as $fn) {
        try {
            $r = in_array($fn, ['lwbd_check_ssl', 'lwbd_check_disk'], true) ? $fn($refresh) : $fn();
            if ($r) {
                $items[] = $r;
            }
        } catch (\Throwable $e) {
            logActivity('Lwb-dashboard (' . $fn . ') : ' . $e->getMessage());
        }
    }
    return lwbd_sort_levels($items);
}

/** Trie par gravité, en gardant l'ordre d'origine à gravité égale. */
function lwbd_sort_levels(array $items)
{
    $rank = ['critical' => 0, 'serious' => 1, 'warning' => 2, 'info' => 3, 'good' => 4];
    $i = 0;
    foreach ($items as &$it) {
        $it['_o'] = $i++;
    }
    unset($it);
    usort($items, function ($a, $b) use ($rank) {
        return [$rank[$a['level']] ?? 5, $a['_o']] <=> [$rank[$b['level']] ?? 5, $b['_o']];
    });
    return array_map(function ($it) {
        unset($it['_o']);
        return $it;
    }, $items);
}

// ------------------------------------------------------------------ alertes équipe

/** Numéros de l'équipe déclarés dans le module WhatsApp. */
function lwbd_team_phones()
{
    try {
        return lwbd_wha_active() ? \LwbWha\Settings::teamPhones() : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Envoie un message à l'équipe : WhatsApp (file du module, envoi au prochain cron,
 * même pendant les heures calmes) si la passerelle répond, sinon e-mail aux administrateurs.
 *
 * @param string $key   identifiant du message (anti-doublon 24 h côté WhatsApp)
 * @param bool   $email doubler par un e-mail aux administrateurs
 * @return string canal utilisé : whatsapp | email | none
 */
function lwbd_team_notify($key, $subject, $text, $email = false)
{
    $channel = 'none';
    $phones = lwbd_team_phones();
    $open = false;
    if ($phones) {
        try {
            $open = !\LwbWha\Settings::isGateway() || \LwbWha\Gateway::isOpen();
        } catch (\Throwable $e) {
        }
    }
    if ($phones && $open) {
        foreach ($phones as $phone) {
            \LwbWha\Queue::enqueue([
                'client_id' => 0, 'event' => strpos($key, 'digest') !== false ? 'lwbd_digest' : 'lwbd_alert', 'rel_type' => 'lwbd',
                'rel_id' => crc32($key) & 0x7fffffff, 'phone' => $phone, 'message' => $text, 'priority' => 9, 'source' => 'team',
            ]);
        }
        $channel = 'whatsapp';
    }
    if ($email || $channel === 'none') {
        $r = localAPI('SendAdminEmail', ['customsubject' => $subject, 'custommessage' => nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8')), 'type' => 'system']);
        if (($r['result'] ?? '') === 'success' && $channel === 'none') {
            $channel = 'email';
        }
    }
    return $channel;
}

/**
 * Alertes automatiques (cron, au plus toutes les 15 minutes) : une fois par problème
 * et par jour, puis « Résolu » quand il disparaît.
 */
function lwbd_alerts_run($force = false)
{
    $set = lwbd_settings();
    if ($set['alerts'] !== 'on') {
        return [];
    }
    $last = (int) lwbd_state_get('alerts_last', 0);
    if (!$force && $last > time() - 900) {
        return [];
    }
    lwbd_state_set('alerts_last', time());
    $brand = lwbd_brand_name();
    $problems = [];
    foreach (lwbd_monitor() as $m) {
        if (!empty($m['alert'])) {
            $problems[$m['key']] = $m;
        }
    }
    $h = lwbd_health();
    if (!$h['daily']['ok']) {
        $problems['sys:daily'] = ['key' => 'sys:daily', 'title' => 'Tâches quotidiennes WHMCS', 'text' => 'Dernière exécution ' . lwbd_ago($h['daily']['minutes']) . ' : factures et relances du jour non générées.'];
    }
    if ($h['registrar']['no_credit']) {
        $reg = lwbd_main_registrar();
        $problems['sys:credit'] = ['key' => 'sys:credit', 'title' => 'Crédit ' . $reg['name'] . ' épuisé', 'text' => 'Les enregistrements et renouvellements de domaines échouent.'];
    }
    foreach (lwbd_security_alerts() as $s) {
        $problems[$s['key']] = $s;
    }
    $open = lwbd_state_get('alerts_open', []);
    $sent = [];
    foreach ($problems as $key => $p) {
        if (isset($open[$key]) && $open[$key]['t'] > time() - 86400) {
            continue;
        }
        $text = '⚠️ *' . $brand . ' · ' . $p['title'] . "*\n" . $p['text'];
        $channel = lwbd_team_notify('alert:' . $key . ':' . date('Ymd'), $brand . ' : ' . $p['title'], $text, strpos($key, 'whatsapp') !== false);
        $open[$key] = ['t' => time(), 'title' => $p['title'], 'once' => !empty($p['once'])];
        $sent[] = [$key, $channel];
    }
    foreach ($open as $key => $info) {
        if (!isset($problems[$key])) {
            if (empty($info['once'])) {
                lwbd_team_notify('resolved:' . $key . ':' . date('YmdH'), $brand . ' : résolu', '✅ *' . $brand . ' · Résolu*' . "\n" . $info['title']);
            }
            unset($open[$key]);
        }
    }
    lwbd_state_set('alerts_open', $open);
    if ($sent) {
        logActivity('Lwb-dashboard : alerte(s) envoyée(s) à l\'équipe : ' . implode(', ', array_map(function ($s) {
            return $s[0] . ' (' . $s[1] . ')';
        }, $sent)));
    }
    return $sent;
}
