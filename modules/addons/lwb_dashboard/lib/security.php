<?php
/**
 * Lwb-dashboard — sécurité de l'administration :
 *   - fichiers PHP ajoutés ou modifiés dans WHMCS (analyse quotidienne, empreinte taille + date) ;
 *   - connexions administrateur depuis un nouveau lieu (pays si connu, sinon réseau) ;
 *   - tentatives de connexion échouées.
 *
 * Rien n'est bloqué ni supprimé : le tableau de bord signale, l'administrateur décide.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

const LWBD_FILES_TABLE = 'mod_lwbd_files';
const LWBD_FILES_SKIP = ['templates_c', 'attachments', 'downloads', '.git', 'node_modules', 'cache'];

function lwbd_files_table()
{
    if (!Capsule::schema()->hasTable(LWBD_FILES_TABLE)) {
        Capsule::schema()->create(LWBD_FILES_TABLE, function ($t) {
            $t->char('id', 32)->primary();
            $t->text('path');
            $t->string('sig', 40);
        });
    }
}

// ------------------------------------------------------------------ fonctions pures

/**
 * Compare deux index [chemin => empreinte].
 *
 * @return array ['new' => [chemins], 'changed' => [chemins], 'removed' => [chemins]]
 */
function lwbd_files_diff(array $old, array $now)
{
    $new = array_keys(array_diff_key($now, $old));
    $removed = array_keys(array_diff_key($old, $now));
    $changed = [];
    foreach (array_intersect_key($now, $old) as $path => $sig) {
        if ($old[$path] !== $sig) {
            $changed[] = $path;
        }
    }
    sort($new);
    sort($changed);
    sort($removed);
    return ['new' => $new, 'changed' => $changed, 'removed' => $removed];
}

/** Contenu typique d'une porte dérobée PHP ? */
function lwbd_looks_malicious($code)
{
    return (bool) preg_match('/(eval|assert)\s*\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13|\$_(POST|GET|REQUEST|COOKIE))|'
        . '(shell_exec|passthru|system|exec|popen|proc_open)\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)|'
        . 'preg_replace\s*\(\s*[\'"][^\'"]*\/e[a-z]*[\'"]|'
        . 'move_uploaded_file\s*\([^)]*\$_FILES[^)]*\)\s*;\s*\?>\s*$|'
        . '\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}/i', (string) $code);
}

/** Nom de fichier suspect pour un WHMCS (fichier WordPress, nom aléatoire, double extension). */
function lwbd_suspicious_name($path)
{
    $base = strtolower(basename((string) $path));
    // wp-xxx.php, nom aléatoire (lettres et chiffres mélangés), image.php, fichier caché .xxx.php
    return (bool) preg_match('/^wp-[a-z0-9_-]+\.php$|^(?=[a-z0-9]*\d)(?=[a-z0-9]*[a-z])[a-z0-9]{10,}\.php$|\.(jpg|jpeg|png|gif|txt|ico)\.php$|^\.[a-z0-9_-]+\.php$/', $base);
}

/** Clé de lieu d'une connexion : pays s'il est connu, sinon réseau (/16 en IPv4). */
function lwbd_location_key($ip, $country = null)
{
    if ($country) {
        return 'cc:' . strtoupper($country);
    }
    $ip = trim((string) $ip);
    if (strpos($ip, ':') !== false) {
        return 'v6:' . implode(':', array_slice(explode(':', $ip), 0, 3));
    }
    $p = explode('.', $ip);
    return count($p) === 4 ? 'net:' . $p[0] . '.' . $p[1] : 'ip:' . $ip;
}

// ------------------------------------------------------------------ fichiers

/** Index courant des fichiers PHP et .htaccess de WHMCS : [chemin relatif => empreinte]. */
function lwbd_files_index($root)
{
    $index = [];
    $dir = new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
    $filter = new \RecursiveCallbackFilterIterator($dir, function ($f) {
        if ($f->isDir()) {
            return !in_array($f->getFilename(), LWBD_FILES_SKIP, true) && !$f->isLink();
        }
        return (bool) preg_match('/\.(php\d?|phtml|phar|inc)$|^\.htaccess$/i', $f->getFilename());
    });
    foreach (new \RecursiveIteratorIterator($filter) as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        $index[$rel] = $f->getSize() . '-' . $f->getMTime();
    }
    return $index;
}

/**
 * Analyse quotidienne : compare les fichiers à l'index précédent et met à jour le rapport.
 * Première analyse ou mise à jour de WHMCS : nouvel index sans alerte.
 */
function lwbd_files_scan()
{
    @set_time_limit(300);
    lwbd_files_table();
    $root = rtrim(defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 4), '/');
    $now = lwbd_files_index($root);
    $old = Capsule::table(LWBD_FILES_TABLE)->pluck('sig', 'path')->all();
    $version = (string) \WHMCS\Config\Setting::getValue('Version');
    $baseline = !$old;
    $updated = !$baseline && lwbd_state_get('files_version') !== null && lwbd_state_get('files_version') !== $version;
    $diff = lwbd_files_diff($old, $now);

    // Mise à jour de l'index (par paquets).
    Capsule::table(LWBD_FILES_TABLE)->whereIn('id', array_map('md5', $diff['removed']))->delete();
    $rows = [];
    foreach (array_merge($diff['new'], $diff['changed']) as $path) {
        $rows[] = ['id' => md5($path), 'path' => $path, 'sig' => $now[$path]];
    }
    foreach (array_chunk($rows, 300) as $chunk) {
        Capsule::table(LWBD_FILES_TABLE)->whereIn('id', array_column($chunk, 'id'))->delete();
        Capsule::table(LWBD_FILES_TABLE)->insert($chunk);
    }
    lwbd_state_set('files_version', $version);

    $report = lwbd_state_get('files_report') ?: ['new' => [], 'changed' => [], 'removed' => 0];
    $report['scanned'] = time();
    $report['count'] = count($now);
    if ($baseline || $updated) {
        $report['note'] = $baseline ? 'Premier index : ' . count($now) . ' fichiers surveillés.' : 'WHMCS mis à jour (' . $version . ') : nouvel index sans alerte.';
        lwbd_state_set('files_report', $report);
        return $report;
    }
    $describe = function ($path) use ($root) {
        $code = @file_get_contents($root . '/' . $path, false, null, 0, 400000);
        return ['path' => $path, 'suspect' => lwbd_suspicious_name($path) || lwbd_looks_malicious($code), 'at' => time()];
    };
    foreach (['new', 'changed'] as $kind) {
        foreach ($diff[$kind] as $path) {
            $report[$kind][$path] = $describe($path);
        }
        // On garde les 60 plus récents, suspects d'abord.
        uasort($report[$kind], function ($a, $b) {
            return [$b['suspect'], $b['at']] <=> [$a['suspect'], $a['at']];
        });
        $report[$kind] = array_slice($report[$kind], 0, 60, true);
    }
    $report['removed'] = (int) ($report['removed'] ?? 0) + count($diff['removed']);
    if ($diff['new'] || $diff['changed'] || $diff['removed']) {
        $report['since'] = $report['since'] ?? time();
        unset($report['note']);
    }
    lwbd_state_set('files_report', $report);
    return $report;
}

/** Rapport des fichiers (null si rien à signaler). */
function lwbd_files_report()
{
    $r = lwbd_state_get('files_report');
    return is_array($r) ? $r : null;
}

/** L'administrateur a vérifié les changements : on vide la liste. */
function lwbd_files_ack()
{
    $r = lwbd_files_report() ?: [];
    lwbd_state_set('files_report', ['new' => [], 'changed' => [], 'removed' => 0, 'scanned' => $r['scanned'] ?? null, 'count' => $r['count'] ?? null, 'acked' => time()]);
}

// ------------------------------------------------------------------ connexions

/** Pays connus des adresses IP (table du module lwb_ip, si installé). */
function lwbd_ip_geo(array $ips)
{
    if (!$ips || !Capsule::schema()->hasTable('mod_lwb_ip_geo')) {
        return [];
    }
    $out = [];
    foreach (Capsule::table('mod_lwb_ip_geo')->whereIn('ip', array_values(array_unique($ips)))->get(['ip', 'country', 'country_code', 'city']) as $g) {
        $out[$g->ip] = ['code' => $g->country_code, 'label' => trim($g->city . ($g->city && $g->country ? ', ' : '') . $g->country)];
    }
    return $out;
}

/**
 * Connexions administrateur : nouveaux lieux (14 jours), échecs (7 jours), dernières connexions.
 */
function lwbd_admin_logins()
{
    $rows = Capsule::table('tbladminlog')->where('logintime', '>=', date('Y-m-d H:i:s', strtotime('-120 days')))
        ->orderBy('id')->get(['id', 'adminusername', 'logintime', 'ipaddress'])->all();
    $geo = lwbd_ip_geo(array_column($rows, 'ipaddress'));
    $seen = [];
    $new = [];
    $recent = date('Y-m-d H:i:s', strtotime('-14 days'));
    foreach ($rows as $r) {
        $g = $geo[$r->ipaddress] ?? null;
        $key = lwbd_location_key($r->ipaddress, $g['code'] ?? null);
        $user = (string) $r->adminusername;
        if ($r->logintime >= $recent && !empty($seen[$user]) && !isset($seen[$user][$key])) {
            $new[] = ['id' => (int) $r->id, 'admin' => $user, 'ip' => $r->ipaddress, 'time' => $r->logintime, 'where' => $g['label'] ?? 'réseau ' . substr($key, strpos($key, ':') + 1) . '.x.x'];
        }
        $seen[$user][$key] = true;
    }
    $failedRows = Capsule::table('tblactivitylog')->where('date', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
        ->where('description', 'like', 'Failed Admin Login%')->orderBy('id', 'desc')->get(['date', 'description'])->all();
    $ips = [];
    foreach ($failedRows as $f) {
        if (preg_match('/IP:\s*([0-9a-f.:]+)/i', $f->description, $m)) {
            $ips[$m[1]] = ($ips[$m[1]] ?? 0) + 1;
        }
    }
    arsort($ips);
    $day = 0;
    foreach ($failedRows as $f) {
        if ($f->date >= date('Y-m-d H:i:s', time() - 86400)) {
            $day++;
        }
    }
    return [
        'new' => array_reverse($new),
        'failed' => count($failedRows),
        'failed_day' => $day,
        'failed_ips' => array_slice($ips, 0, 5, true),
        'last' => array_map(function ($r) use ($geo) {
            return ['admin' => $r->adminusername, 'time' => $r->logintime, 'ip' => $r->ipaddress, 'where' => $geo[$r->ipaddress]['label'] ?? ''];
        }, array_slice(array_reverse($rows), 0, 5)),
    ];
}

/** Tout ce que la sécurité signale, pour le tableau de bord et « À faire ». */
function lwbd_security()
{
    $files = lwbd_files_report();
    $logins = lwbd_admin_logins();
    $newFiles = $files ? array_values($files['new'] ?? []) : [];
    $changed = $files ? array_values($files['changed'] ?? []) : [];
    $suspect = count(array_filter(array_merge($newFiles, $changed), function ($f) {
        return !empty($f['suspect']);
    }));
    return ['files' => $files, 'new_files' => $newFiles, 'changed_files' => $changed, 'removed' => (int) ($files['removed'] ?? 0),
        'suspect' => $suspect, 'logins' => $logins];
}

/** Problèmes de sécurité à signaler à l'équipe (une seule fois chacun). */
function lwbd_security_alerts()
{
    $out = [];
    try {
        $s = lwbd_security();
    } catch (\Throwable $e) {
        return [];
    }
    $n = count($s['new_files']) + count($s['changed_files']);
    if ($n) {
        $list = array_slice(array_merge($s['new_files'], $s['changed_files']), 0, 5);
        $out[] = ['key' => 'sec:files:' . (int) ($s['files']['since'] ?? 0), 'once' => true,
            'title' => ($s['suspect'] ? 'Fichier(s) suspect(s) dans WHMCS' : 'Fichiers modifiés dans WHMCS'),
            'text' => count($s['new_files']) . ' nouveau(x), ' . count($s['changed_files']) . ' modifié(s)' . ($s['suspect'] ? ', dont ' . $s['suspect'] . ' suspect(s)' : '') . " :\n"
                . implode("\n", array_map(function ($f) {
                    return '• ' . $f['path'] . (!empty($f['suspect']) ? ' ⚠️' : '');
                }, $list)) . "\nVérifiez-les dans le tableau de bord (carte Sécurité)."];
    }
    foreach ($s['logins']['new'] as $l) {
        if (strtotime($l['time']) > time() - 86400) {
            $out[] = ['key' => 'sec:login:' . $l['id'], 'once' => true, 'title' => 'Connexion admin depuis un nouveau lieu',
                'text' => $l['admin'] . ' · ' . $l['where'] . ' · IP ' . $l['ip'] . ' · ' . date('d/m H:i', strtotime($l['time'])) . "\nSi ce n'est pas vous, changez le mot de passe et fermez les sessions."];
        }
    }
    if ($s['logins']['failed_day'] >= 10) {
        $out[] = ['key' => 'sec:brute:' . date('Ymd'), 'once' => true, 'title' => 'Tentatives de connexion admin',
            'text' => $s['logins']['failed_day'] . ' échecs de connexion à l\'administration en 24 h. IP les plus actives : ' . implode(', ', array_keys($s['logins']['failed_ips']))];
    }
    return $out;
}
