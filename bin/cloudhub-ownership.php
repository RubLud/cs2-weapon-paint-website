<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$args = array_slice($argv, 1);
$reason = null;
foreach ($args as $i => $a) if (str_starts_with($a, '--reason=')) { $reason = substr($a, 9); unset($args[$i]); }
$args = array_values($args);
$mode = $args[0] ?? '--dry-run';
$modes = ['--dry-run', '--apply', '--audit', '--guard=install', '--guard=remove', '--guard=status'];
if (count($args) > 1 || !in_array($mode, $modes, true) || (in_array($mode, ['--apply', '--guard=install'], true) && trim((string)$reason) === '')) {
    fwrite(STDERR, "Usage: CLOUDHUB_CONFIG=/private/config.php php bin/cloudhub-ownership.php [--dry-run | --apply --reason=\"...\" | --audit | --guard=install --reason=\"...\" | --guard=remove | --guard=status]\n"
        . "--dry-run (default) counts what WeaponPaints already stores per player: equipped skins, knives, gloves and saved presets.\n"
        . "--apply records those items as legacy ownership so nobody loses them. Additive and repeatable; nothing is deleted.\n"
        . "Run it right before setting CLOUDHUB_OWNERSHIP_ENFORCED, with OWNER approval. Enforcement stays off until one snapshot exists.\n"
        . "--audit lists equipped items enforcement would refuse (e.g. picked with in-game menus). Read-only.\n"
        . "--guard=install takes a fresh snapshot and adds triggers to wp_player_skins/knife/gloves so the CS2 server (and its\n"
        . "  in-game menus) can only store owned items; unowned choices are stored as the default. Needs web enforcement active\n"
        . "  and the TRIGGER privilege. --guard=remove drops the triggers; stored equipment is never changed.\n");
    exit(1);
}
if (!defined('DB_AUTO_MIGRATE')) { define('DB_AUTO_MIGRATE', false); }
$api = is_dir(__DIR__ . '/../backend') ? __DIR__ . '/../backend' : __DIR__ . '/../public/api';
require_once $api . '/class/Database.php';
require_once $api . '/class/CloudHubOwnership.php';
$db = Database::getInstance()->getConnection();
$ownership = new CloudHubOwnership($db);
$result = match ($mode) {
    '--audit' => $ownership->audit(),
    '--guard=install' => $ownership->installGuard((string)$reason),
    '--guard=remove' => $ownership->removeGuard(),
    '--guard=status' => ['triggers' => $ownership->guardTriggers()],
    default => $ownership->snapshot($mode === '--apply', (string)$reason),
};
$result['enforcement_active'] = CloudHubOwnership::active($db);
$result['guard_installed'] = count($ownership->guardTriggers()) === 6;
if ($result['guard_installed'] && !$result['enforcement_active']) fwrite(STDERR, "Warning: the database guard is installed but web enforcement is off. Run --guard=remove or enable enforcement.\n");
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
