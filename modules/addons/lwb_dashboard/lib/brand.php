<?php
/**
 * Lwb-dashboard — marque, devise et intégrations : tout ce qui change d'un WHMCS à l'autre.
 *
 * Rien n'est écrit en dur : le nom vient des réglages (ou du nom de la société dans WHMCS),
 * la devise de la devise par défaut de WHMCS, et les intégrations (FlexPay, registraire)
 * ne s'affichent que si elles sont réellement utilisées.
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('Accès direct interdit.');
}

const LWBD_BRAND_DEFAULTS = [
    'brand_name' => '',
    'brand_logo' => '',
    'brand_color' => '#030673',
    'brand_accent' => '#ff6a3d',
    'brand_font' => 'jakarta',
    'featured_group' => '',
    'login_theme' => 'on',
];

const LWBD_FONTS = [
    'jakarta' => ['Plus Jakarta Sans', 'Plus+Jakarta+Sans:wght@400;500;600;700;800'],
    'inter' => ['Inter', 'Inter:wght@400;500;600;700;800'],
    'system' => ['', ''],
];

/** Réglages de marque (tbladdonmodules), avec valeurs par défaut. */
function lwbd_brand($refresh = false)
{
    static $brand = null;
    if ($brand !== null && !$refresh) {
        return $brand;
    }
    $rows = Capsule::table('tbladdonmodules')->where('module', 'lwb_dashboard')
        ->whereIn('setting', array_keys(LWBD_BRAND_DEFAULTS))->pluck('value', 'setting')->all();
    $brand = array_merge(LWBD_BRAND_DEFAULTS, array_filter($rows, function ($v) {
        return $v !== null && $v !== '';
    }));
    if ($brand['brand_name'] === '') {
        $brand['brand_name'] = (string) \WHMCS\Config\Setting::getValue('CompanyName') ?: 'WHMCS';
    }
    // Nom et logo finissent dans du CSS : ni balise, ni guillemet, ni barre oblique inverse.
    $brand['brand_name'] = trim(str_replace(['<', '>', '"', '\\'], '', html_entity_decode($brand['brand_name'], ENT_QUOTES, 'UTF-8')));
    if (!preg_match('#^(https?://|\.\./|/)[^\s"\'<>()\\\\]+$#i', $brand['brand_logo'])) {
        $brand['brand_logo'] = '';
    }
    foreach (['brand_color', 'brand_accent'] as $k) {
        if (!preg_match('/^#[0-9a-f]{6}$/i', $brand[$k])) {
            $brand[$k] = LWBD_BRAND_DEFAULTS[$k];
        }
    }
    if (!isset(LWBD_FONTS[$brand['brand_font']])) {
        $brand['brand_font'] = LWBD_BRAND_DEFAULTS['brand_font'];
    }
    $brand['slug'] = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $brand['brand_name']) ?: 'lwb')), '-') ?: 'lwb';
    return $brand;
}

/** Valeur enregistrée telle quelle (sans valeur par défaut), pour pré-remplir les réglages. */
function lwbd_brand_raw($key)
{
    return (string) Capsule::table('tbladdonmodules')->where('module', 'lwb_dashboard')->where('setting', $key)->value('value');
}

/** Enregistre la carte « Marque » des réglages. Retourne un message d'erreur ou null. */
function lwbd_brand_save(array $post)
{
    $name = trim(str_replace(['<', '>', '"', '\\'], '', (string) ($post['brand_name'] ?? '')));
    $logo = trim((string) ($post['brand_logo'] ?? ''));
    if ($logo !== '' && !preg_match('#^(https?://|\.\./|/)[^\s"\'<>()\\\\]+$#i', $logo)) {
        return 'Le logo doit être une adresse web (https://…) sans espace ni guillemet.';
    }
    $color = (string) ($post['brand_color'] ?? '');
    $accent = (string) ($post['brand_accent'] ?? '');
    $font = (string) ($post['brand_font'] ?? '');
    $group = (int) ($post['featured_group'] ?? 0);
    $values = [
        'brand_name' => mb_substr($name, 0, 60),
        'brand_logo' => $logo,
        'brand_color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? strtolower($color) : LWBD_BRAND_DEFAULTS['brand_color'],
        'brand_accent' => preg_match('/^#[0-9a-f]{6}$/i', $accent) ? strtolower($accent) : LWBD_BRAND_DEFAULTS['brand_accent'],
        'brand_font' => isset(LWBD_FONTS[$font]) ? $font : LWBD_BRAND_DEFAULTS['brand_font'],
        'featured_group' => $group > 0 ? (string) $group : '',
        'login_theme' => !empty($post['login_theme']) ? 'on' : 'off',
    ];
    foreach ($values as $k => $v) {
        lwbd_save_setting($k, $v);
    }
    lwbd_brand(true);
    return null;
}

function lwbd_brand_name()
{
    return lwbd_brand()['brand_name'];
}

/** Éclaircit (ratio > 0) ou assombrit (ratio < 0) une couleur #rrggbb. */
function lwbd_shade($hex, $ratio)
{
    $out = '#';
    foreach (str_split(substr($hex, 1), 2) as $c) {
        $v = hexdec($c);
        $v = $ratio > 0 ? $v + (255 - $v) * $ratio : $v * (1 + $ratio);
        $out .= str_pad(dechex((int) round(max(0, min(255, $v)))), 2, '0', STR_PAD_LEFT);
    }
    return $out;
}

function lwbd_rgb($hex)
{
    return implode(',', array_map('hexdec', str_split(substr($hex, 1), 2)));
}

/**
 * Feuille de style de la marque : police, couleurs, logo.
 * Avec les réglages par défaut, seule la police est chargée : le rendu d'origine reste identique.
 *
 * @param bool $login version pour la page de connexion (hors administration)
 */
function lwbd_brand_css($login = false)
{
    $b = lwbd_brand();
    [$family, $google] = LWBD_FONTS[$b['brand_font']];
    $stack = ($family !== '' ? '"' . $family . '",' : '') . '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif';
    $css = $google !== '' ? '@import url("https://fonts.googleapis.com/css2?family=' . $google . '&display=swap");' . "\n" : '';
    $brand = $b['brand_color'];
    $accent = $b['brand_accent'];
    $custom = $brand !== LWBD_BRAND_DEFAULTS['brand_color'] || $accent !== LWBD_BRAND_DEFAULTS['brand_accent'];
    $dark = lwbd_shade($brand, -0.32);
    $light = lwbd_shade($brand, 0.06);
    if ($login) {
        $css .= 'html body,html body *{font-family:' . $stack . '!important}' . "\n";
        if ($custom) {
            $css .= 'html body{background:radial-gradient(1200px 600px at 85% -10%,rgba(' . lwbd_rgb($accent) . ',.28),transparent 60%),radial-gradient(900px 600px at -10% 110%,rgba(' . lwbd_rgb(lwbd_shade($brand, 0.3)) . ',.35),transparent 60%),linear-gradient(160deg,' . $light . ' 0%,' . $brand . ' 50%,' . $dark . ' 100%)!important}' . "\n"
                . '.login-body .btn-primary,.login-body button[type=submit],.login-body input[type=submit]{background:' . $brand . '!important}' . "\n"
                . '.login-body .btn-primary:hover,.login-body button[type=submit]:hover{background:' . lwbd_shade($brand, 0.12) . '!important}' . "\n"
                . '.login-body a{color:' . $brand . '!important}.login-body input[type=checkbox]{accent-color:' . $brand . '}' . "\n"
                . '.login-body .form-control:focus,.login-body input:focus{border-color:' . $brand . '!important;box-shadow:0 0 0 4px rgba(' . lwbd_rgb($brand) . ',.10)!important}' . "\n";
        }
        if ($b['brand_logo'] !== '') {
            $css .= '.login-container h1.logo img{content:url(' . json_encode($b['brand_logo'], JSON_UNESCAPED_SLASHES) . ')}' . "\n";
        }
        return $css . '.login-body h2::after{content:' . json_encode("Espace d'administration " . $b['brand_name'], JSON_UNESCAPED_UNICODE) . '}' . "\n";
    }
    $css .= 'html.lwba.lwba{--a-font:' . $stack . '}html .lwbd{font-family:' . $stack . '}' . "\n";
    if ($custom) {
        $css .= 'html.lwba.lwba{--a-brand:' . $brand . ';--a-brand2:' . lwbd_shade($brand, 0.12) . ';--a-brand50:' . lwbd_shade($brand, 0.93) . ';--a-accent:' . $accent . '}' . "\n"
            . 'html.lwba.lwba .navigation{background:linear-gradient(180deg,' . $light . ' 0%,' . $brand . ' 45%,' . $dark . ' 100%)!important}' . "\n"
            . 'html .lwbd{--brand:' . $brand . ';--brand-50:' . lwbd_shade($brand, 0.93) . ';--accent:' . $accent . ';--series-1:' . $brand . '}' . "\n";
    }
    return $css;
}

/** Balise <style> de la marque pour les pages de l'administration. */
function lwbd_brand_style()
{
    return '<style id="lwbd-brand">' . lwbd_brand_css(false) . '</style>';
}

/** Chemin de la feuille de connexion générée (hors Git : elle dépend de chaque installation). */
function lwbd_login_css_path()
{
    return dirname(__DIR__) . '/assets/brand-login.css';
}

function lwbd_login_css_write()
{
    $file = lwbd_login_css_path();
    $css = "/* Généré par Lwb-dashboard à partir des réglages de marque : ne pas modifier. */\n" . lwbd_brand_css(true);
    return @file_put_contents($file . '.tmp', $css) !== false && @rename($file . '.tmp', $file);
}

/** Devise par défaut de WHMCS : symbole affiché après le montant (« USD », « € », « $ »…). */
function lwbd_currency_symbol()
{
    static $sym = null;
    if ($sym === null) {
        $cur = Capsule::table('tblcurrencies')->where('default', 1)->first(['code', 'prefix', 'suffix']);
        $sym = $cur ? (trim((string) $cur->suffix) ?: trim((string) $cur->prefix) ?: (string) $cur->code) : '';
        $sym = html_entity_decode($sym, ENT_QUOTES, 'UTF-8');
    }
    return $sym;
}

/** FlexPay installé et actif ? (paiement Mobile Money sans connexion) */
function lwbd_flexpay_active()
{
    static $on = null;
    if ($on === null) {
        $on = false;
        $lib = ROOTDIR . '/modules/gateways/flexpay/lib/Gateway.php';
        try {
            if (is_file($lib)) {
                require_once $lib;
                $on = class_exists('\\FlexPayWhmcs\\Gateway') && \FlexPayWhmcs\Gateway::isActive();
            }
        } catch (\Throwable $e) {
        }
    }
    return $on;
}

/** Registraire le plus utilisé par les domaines actifs : [module, nom lisible, lien de recharge]. */
function lwbd_main_registrar()
{
    static $reg = null;
    if ($reg === null) {
        $module = (string) Capsule::table('tbldomains')->where('status', 'Active')->where('registrar', '!=', '')
            ->selectRaw('registrar, COUNT(*) AS n')->groupBy('registrar')->orderByDesc('n')->value('registrar');
        $known = [
            'porkbun' => ['Porkbun', 'https://porkbun.com/account/credit'],
            'namecheap' => ['Namecheap', 'https://ap.www.namecheap.com/profile/billing/topup'],
            'resellerclub' => ['ResellerClub', 'https://manage.resellerclub.com/'],
            'enom' => ['eNom', 'https://www.enom.com/'],
            'openprovider' => ['Openprovider', 'https://cp.openprovider.eu/'],
        ];
        [$name, $url] = $known[strtolower($module)] ?? [$module !== '' ? ucfirst($module) : 'du registraire', 'configregistrars.php'];
        $reg = ['module' => $module, 'name' => $name, 'url' => $url];
    }
    return $reg;
}

/**
 * Table anti-doublon des relances. On réutilise celle des relances automatiques
 * de LEWEBMAX si elle existe, sinon la nôtre.
 */
function lwbd_reminders_table()
{
    if (Capsule::schema()->hasTable('mod_lwm_reminders')) {
        return 'mod_lwm_reminders';
    }
    if (!Capsule::schema()->hasTable('mod_lwbd_reminders')) {
        Capsule::schema()->create('mod_lwbd_reminders', function ($t) {
            $t->integer('invoice_id')->primary();
            $t->date('last_sent');
            $t->integer('count')->default(0);
        });
    }
    return 'mod_lwbd_reminders';
}
