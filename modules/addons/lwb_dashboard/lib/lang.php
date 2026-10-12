<?php
/**
 * Lwb-dashboard — corrections de la traduction française de l'administration WHMCS.
 *
 * WHMCS lit {admin}/lang/overrides/french.php après sa propre traduction : ce fichier
 * n'est pas écrasé par les mises à jour. On le génère à partir du french.php installé
 * (« billet » → « ticket », « soutien » → « Support », fautes de frappe…), en ne gardant
 * que les phrases qui changent.
 */

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

const LWBD_LANG_MARKER = 'Lwb-dashboard';

/** Dossier de l'administration (kota/ chez LEWEBMAX), tel que configuré dans WHMCS. */
function lwbd_admin_dir()
{
    $dir = 'admin';
    if (class_exists('\App')) {
        try {
            $dir = (string) \App::get_admin_folder_name();
        } catch (\Throwable $e) {
        }
    }
    return ROOTDIR . '/' . trim($dir, '/');
}

/** Corrections mot à mot appliquées à chaque phrase (expressions régulières, ordre important). */
function lwbd_lang_rules()
{
    return [
        '/^soutien$/u' => 'Support',
        '/^Demande de soutien$/u' => 'Tickets de support',
        '/\bBillets\b/u' => 'Tickets',
        '/\bbillets\b/u' => 'tickets',
        '/\bBillet\b/u' => 'Ticket',
        '/\bbillet\b/u' => 'ticket',
        '/\bAffiliatés\b/u' => 'Affiliés',
        '/\bAddresse\b/u' => 'Adresse',
        '/^Bureau d\'enregistrement$/u' => 'Registraire',
        '/^Des services$/u' => 'Services',
        '/^Statuts du paiement$/u' => 'Statut du paiement',
        '/^Grace$/u' => 'Période de grâce',
        '/^Collections$/u' => 'Recouvrement',
    ];
}

/** Phrases corrigées à la main (accords, tournures) : clé « a.b.c » => texte. */
function lwbd_lang_fixed()
{
    return [
        'mergefields.condisplay3' => 'Votre ticket a été reçu et sera traité sous peu',
        'support.numticketsassigned' => 'Vous avez %s ticket(s) assigné(s) qui demandent votre attention.',
        'support.addprivatenote' => 'Ajouter au ticket comme note privée',
        // Phrase insérée par WHMCS dans un script entre apostrophes droites : elle cassait la page « Compatibilité PHP ».
        'phpCompatUtil.scanError' => 'Une erreur s’est produite lors de l’analyse des fichiers d’installation.',
    ];
}

/** Applique les règles à un tableau de traduction et ne garde que ce qui change. */
function lwbd_lang_diff(array $lang, $prefix = '')
{
    $out = [];
    $rules = lwbd_lang_rules();
    $fixed = lwbd_lang_fixed();
    foreach ($lang as $k => $v) {
        $key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
        if (is_array($v)) {
            $sub = lwbd_lang_diff($v, $key);
            if ($sub) {
                $out[$k] = $sub;
            }
            continue;
        }
        if (!is_string($v)) {
            continue;
        }
        $new = $fixed[$key] ?? preg_replace(array_keys($rules), array_values($rules), $v);
        if ($new !== $v) {
            $out[$k] = $new;
        }
    }
    return $out;
}

/** Transforme le tableau en lignes PHP « $_ADMINLANG['a']['b'] = "..."; ». */
function lwbd_lang_lines(array $diff, array $path = [])
{
    $lines = [];
    foreach ($diff as $k => $v) {
        $p = array_merge($path, [$k]);
        if (is_array($v)) {
            $lines = array_merge($lines, lwbd_lang_lines($v, $p));
            continue;
        }
        $idx = '';
        foreach ($p as $part) {
            $idx .= '[' . var_export((string) $part, true) . ']';
        }
        $lines[] = '$_ADMINLANG' . $idx . ' = ' . var_export($v, true) . ';';
    }
    return $lines;
}

/**
 * Écrit (ou met à jour) kota/lang/overrides/french.php.
 * Un fichier d'overrides déjà présent et qui n'est pas le nôtre n'est jamais remplacé.
 *
 * @return array{ok:bool,count?:int,error?:string}
 */
function lwbd_lang_install()
{
    try {
        $langDir = lwbd_admin_dir() . '/lang';
        $source = $langDir . '/french.php';
        $target = $langDir . '/overrides/french.php';
        if (!is_file($source)) {
            return ['ok' => false, 'error' => 'french.php introuvable'];
        }
        if (is_file($target) && strpos((string) file_get_contents($target), LWBD_LANG_MARKER) === false) {
            return ['ok' => false, 'error' => 'overrides/french.php existe déjà (non modifié)'];
        }
        $lang = (function ($file) {
            $_ADMINLANG = [];
            include $file;
            return $_ADMINLANG;
        })($source);
        $diff = lwbd_lang_diff($lang);
        $lines = lwbd_lang_lines($diff);
        if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, true)) {
            return ['ok' => false, 'error' => 'dossier overrides impossible à créer'];
        }
        $php = "<?php\n/**\n * " . LWBD_LANG_MARKER . " — corrections de la traduction française de l'administration.\n"
            . " * Fichier généré automatiquement (" . date('d/m/Y H:i') . ") : ne pas modifier à la main,\n"
            . " * il est recréé à chaque mise à jour de l'extension.\n */\n\nif (!defined('WHMCS')) {\n    die('Accès direct interdit.');\n}\n\n"
            . implode("\n", $lines) . "\n";
        if (@file_put_contents($target . '.tmp', $php) === false || !@rename($target . '.tmp', $target)) {
            return ['ok' => false, 'error' => 'écriture impossible'];
        }
        return ['ok' => true, 'count' => count($lines)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
