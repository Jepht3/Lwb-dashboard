<?php
/**
 * Tests des fonctions pures de LWB Admin (sans WHMCS ni base de données).
 * Lancement : php tests/run.php
 */

define('WHMCS', true);
error_reporting(E_ALL);
date_default_timezone_set('UTC');

$lib = __DIR__ . '/../modules/addons/lwb_dashboard/lib/';
foreach (['brand', 'data', 'health', 'monitor', 'security', 'insights', 'digest', 'search', 'todo'] as $f) {
    require_once $lib . $f . '.php';
}

$pass = 0;
$fail = 0;
function check($label, $got, $want)
{
    global $pass, $fail;
    if ($got === $want) {
        $pass++;
        return;
    }
    $fail++;
    echo "ÉCHEC : $label\n  attendu : " . var_export($want, true) . "\n  obtenu  : " . var_export($got, true) . "\n";
}

// Dates et téléphones
check('jours entre deux dates', lwbd_days_between('2026-10-20', '2026-10-24'), 4);
check('jours négatifs', lwbd_days_between('2026-10-24 10:00:00', '2026-10-20'), -4);
check('il y a 5 min', lwbd_ago(5), 'il y a 5 min');
check('il y a 3 h', lwbd_ago(190), 'il y a 3 h');
check('il y a 2 j', lwbd_ago(3000), 'il y a 2 j');
check('jamais', lwbd_ago(null), 'jamais');
check('WhatsApp RDC 0…', lwbd_wa_phone('0907 173 030', 'CD'), '243907173030');
check('WhatsApp RDC 9 chiffres', lwbd_wa_phone('907173030', 'CD'), '243907173030');
check('WhatsApp international', lwbd_wa_phone('+33 6 12 34 56 78', 'FR'), '33612345678');
check('WhatsApp vide', lwbd_wa_phone('', 'CD'), '');

// Niveaux de surveillance
check('SSL 3 jours', lwbd_cert_level(3), 'critical');
check('SSL 15 jours', lwbd_cert_level(15), 'warning');
check('SSL 60 jours', lwbd_cert_level(60), 'good');
check('SSL inconnu', lwbd_cert_level(null), 'info');
check('Sauvegarde récente', lwbd_backup_level(20), 'good');
check('Sauvegarde 36 h', lwbd_backup_level(36), 'serious');
check('Sauvegarde 3 jours', lwbd_backup_level(72), 'critical');
check('Aucune sauvegarde', lwbd_backup_level(null), 'warning');
check('Disque 50 %', lwbd_disk_level(50), 'good');
check('Disque 85 %', lwbd_disk_level(85), 'warning');
check('Disque 95 %', lwbd_disk_level(95), 'critical');
check('Octets', lwbd_bytes(9502919), '9 Mo');
check('Gigaoctets', lwbd_bytes(51772.94 * 1048576), '50,6 Go');
$sorted = lwbd_sort_levels([['level' => 'good', 'k' => 1], ['level' => 'critical', 'k' => 2], ['level' => 'warning', 'k' => 3], ['level' => 'critical', 'k' => 4]]);
check('Tri par gravité', array_column($sorted, 'k'), [2, 4, 3, 1]);

// Sécurité
$diff = lwbd_files_diff(['a.php' => '1-1', 'b.php' => '2-2', 'c.php' => '3-3'], ['a.php' => '1-1', 'b.php' => '2-9', 'd.php' => '4-4']);
check('Fichiers nouveaux', $diff['new'], ['d.php']);
check('Fichiers modifiés', $diff['changed'], ['b.php']);
check('Fichiers supprimés', $diff['removed'], ['c.php']);
check('Porte dérobée eval/base64', lwbd_looks_malicious('<?php eval(base64_decode($x));'), true);
check('Porte dérobée system($_GET)', lwbd_looks_malicious('<?php system($_GET["c"]);'), true);
check('Code normal', lwbd_looks_malicious('<?php echo htmlspecialchars($name); $x = base64_decode($y);'), false);
check('Nom suspect wp-abupee.php', lwbd_suspicious_name('wp-abupee.php'), true);
check('Nom aléatoire', lwbd_suspicious_name('includes/a8f3k2j9d1x.php'), true);
check('Double extension', lwbd_suspicious_name('logo.jpg.php'), true);
check('Nom normal clientarea.php', lwbd_suspicious_name('clientarea.php'), false);
check('Nom normal index.php', lwbd_suspicious_name('kota/index.php'), false);
check('Lieu par pays', lwbd_location_key('169.159.220.13', 'cd'), 'cc:CD');
check('Lieu par réseau', lwbd_location_key('169.159.220.13'), 'net:169.159');
check('Même réseau mobile', lwbd_location_key('169.159.212.13') === lwbd_location_key('169.159.220.13'), true);
check('Lieu IPv6', lwbd_location_key('2001:db8:85a3::8a2e:370:7334'), 'v6:2001:db8:85a3');

// Coûts, marge, fidélité
$costs = lwbd_parse_costs("# coûts\ncom = 11,08\n.cd=25\nco.uk = 7.5\n* = 12\nligne invalide\nnet = abc");
check('Coûts lus', $costs, ['com' => 11.08, 'cd' => 25.0, 'co.uk' => 7.5, '*' => 12.0]);
check('Frais fixes', lwbd_parse_costs("Serveur dédié = 120\nLicence WHMCS = 25 USD"), ['serveur dédié' => 120.0, 'licence whmcs' => 25.0]);
check('Extension simple', lwbd_tld('Exemple.COM'), 'com');
check('Extension double', lwbd_tld('exemple.co.uk'), 'co.uk');
check('Coût .com', lwbd_domain_cost('site.com', $costs), 11.08);
check('Coût .co.uk', lwbd_domain_cost('site.co.uk', $costs), 7.5);
check('Coût .gov.uk -> uk puis défaut', lwbd_domain_cost('site.org.uk', $costs + ['uk' => 6.0]), 6.0);
check('Coût par défaut', lwbd_domain_cost('site.xyz', $costs), 12.0);
check('Coût inconnu', lwbd_domain_cost('site.xyz', ['com' => 1.0]), null);
check('Mensuel annuel', lwbd_monthly_amount(120, 'Annually'), 10.0);
check('Mensuel trimestriel', lwbd_monthly_amount(30, 'Quarterly'), 10.0);
check('Paiement unique', lwbd_monthly_amount(99, 'One Time'), 0.0);
check('Date de suspension', lwbd_suspend_date('2026-10-20', 5), '2026-10-25');
check('Fin de mois', lwbd_suspend_date('2026-10-29 00:00:00', 5), '2026-11-03');
check('Taux de perte', lwbd_churn_rate(3, 97), 3.0);
check('Taux de perte vide', lwbd_churn_rate(0, 0), 0.0);

// Résumé du matin
$money = function ($v) {
    return number_format($v, 2, ',', ' ') . ' USD';
};
$d = ['brand' => 'LEWEBMAX', 'date' => '24/10/2026', 'month_label' => 'octobre 2026', 'yesterday' => 120, 'yesterday_count' => 3, 'month' => 250, 'goal' => 500,
    'unpaid' => 310, 'unpaid_count' => 6, 'late' => 120, 'suspensions' => [['client' => 'Tech Innosoft', 'balance' => 49.99, 'date' => '2026-10-25']],
    'renewals' => 4, 'renewals_amount' => 98, 'tickets' => 1, 'orders' => 0, 'problems' => [], 'security' => [], 'url' => 'https://my.example.com/admin/addonmodules.php?module=lwb_dashboard'];
$t = lwbd_digest_text($d, $money);
check('Résumé : titre', strpos($t, '☀️ *Bonjour ! Résumé LEWEBMAX du 24/10/2026*') === 0, true);
check('Résumé : encaissé hier', strpos($t, 'Encaissé hier : *120,00 USD* (3 paiements)') !== false, true);
check('Résumé : objectif', strpos($t, 'Octobre 2026 : 250,00 USD sur 500,00 USD (50 %)') !== false, true);
check('Résumé : suspension listée', strpos($t, 'Tech Innosoft · 49,99 USD · le 25/10') !== false, true);
check('Résumé : système OK', strpos($t, '✅ Système : tout fonctionne') !== false, true);
$d['problems'] = ['Passerelle WhatsApp : Déconnectée depuis 12 min'];
$d['suspensions'] = [];
$d['security'] = ['2 fichier(s) PHP ajouté(s) ou modifié(s) dans WHMCS'];
$t = lwbd_digest_text($d, $money);
check('Résumé : problème signalé', strpos($t, "⚠️ *À vérifier*\n   • Passerelle WhatsApp") !== false, true);
check('Résumé : pas de ligne suspensions', strpos($t, 'Suspensions') === false, true);
check('Résumé : sécurité', strpos($t, '🔐 *Sécurité* : 2 fichier(s)') !== false, true);

// Recherche
check('Sans accents', lwbd_fold('  Règles d\'Escalade '), 'regles descalade');
$menu = lwbd_setup_menu();
$r = lwbd_search_menu('liens application', $menu);
check('Recherche « liens application »', $r[0]['href'] ?? null, 'configapplinks.php');
$r = lwbd_search_menu('serveurs', $menu);
check('Recherche « serveurs »', $r[0]['href'] ?? null, 'configservers.php');
$r = lwbd_search_menu('regles', $menu);
check('Recherche sans accent', $r[0]['label'] ?? null, 'Règles d\'escalade');
check('Recherche vide', lwbd_search_menu('   ', $menu), []);
check('Menu : 6 groupes', count($menu), 6);

echo "\n$pass / " . ($pass + $fail) . " tests réussis\n";
exit($fail ? 1 : 0);
