<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
if (array_diff($args, ['--simulate', '--apply', '--activate']) || (in_array('--activate', $args, true) && !in_array('--apply', $args, true))) {
    fwrite(STDERR, "Usage: php bin/cloudhub-shop-catalog.php --simulate\n       CLOUDHUB_CONFIG=/private/config.php php bin/cloudhub-shop-catalog.php [--apply [--activate]]\n"
        . "Without --apply only a dry run is printed. --apply inserts missing products as inactive proposals; existing prices are never changed.\n"
        . "--activate makes newly inserted products purchasable; use it only after the OWNER approved the price list.\n");
    exit(1);
}
if (!defined('DB_AUTO_MIGRATE')) { define('DB_AUTO_MIGRATE', false); }
$api = is_dir(__DIR__ . '/../backend') ? __DIR__ . '/../backend' : __DIR__ . '/../public/api';
require_once $api . '/class/Security.php';
require_once $api . '/class/CloudHubShopPricing.php';
$products = CloudHubShopPricing::catalog(wp_catalog('weapons.json'), wp_catalog('data/skins_en.json'), wp_catalog('data/gloves_en.json'));
$summary = [];
foreach ($products as $p) {
    $s = &$summary[$p['kind']];
    $s['count'] = ($s['count'] ?? 0) + 1;
    $s['min'] = min($s['min'] ?? PHP_INT_MAX, $p['price_cc']);
    $s['max'] = max($s['max'] ?? 0, $p['price_cc']);
    unset($s);
}
foreach ($summary as $kind => $s) printf("%-12s %5d products, %d-%d CC\n", $kind, $s['count'], $s['min'], $s['max']);
if (in_array('--simulate', $args, true)) {
    foreach (['current (migration 002)' => CloudHubShopPricing::RULES_CURRENT, 'spec 13.1 proposal' => CloudHubShopPricing::RULES_SPEC_13_1] as $label => $rules) {
        echo "\nRules: $label — per 24-round, 40-minute match (hourly caps applied)\n";
        printf("%-14s %6s %6s %10s %10s %10s\n", 'profile', 'RP', 'CC', '100 CC', '400 CC', '2000 CC');
        foreach (CloudHubShopPricing::profiles() as $name => $profile) {
            $r = CloudHubShopPricing::simulate($rules, $profile);
            printf("%-14s %6d %6d %10s %10s %10s\n", $name, $r['rp'], $r['cc'], ...array_map(fn($price) => (string)(CloudHubShopPricing::matchesToBuy($price, $r['cc']) ?? '-'), [100, 400, 2000]));
        }
    }
    exit;
}
require_once $api . '/class/Database.php';
require_once $api . '/class/CloudHubShop.php';
$db = Database::getInstance()->getConnection();
if (!$db->query("SELECT 1 FROM cloudhub_schema_migrations WHERE version='004_cloudhub_shop.sql'")->fetchColumn()) {
    fwrite(STDERR, "Migration 004_cloudhub_shop.sql is not applied. Run bin/cloudhub-migrate.php --apply first.\n");
    exit(1);
}
$result = (new CloudHubShop($db))->seed($products, in_array('--activate', $args, true), in_array('--apply', $args, true));
printf("Catalog %d, already present %d, new %d%s\n", $result['catalog'], $result['existing'], $result['new'], $result['applied'] ? ' (inserted)' : ' (dry run, nothing written)');
