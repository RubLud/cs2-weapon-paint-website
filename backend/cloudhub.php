<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/class/SessionBootstrap.php';
require_once __DIR__ . '/class/ApiProtect.php';
require_once __DIR__ . '/class/CloudHubShop.php';
wp_send_cors_headers();
wp_boot_session();
header('Content-Type: application/json; charset=utf-8');
if (!CloudHubAccounts::enabled()) { wp_json_error(404, 'CloudHub Core is disabled'); }
if (!isset($_SESSION['steamid'])) { wp_json_error(401, 'Not logged in'); }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    wp_json_error(405, 'Read-only endpoint');
}
wp_enforce_rate_limit((string)$_SESSION['steamid']);
// Identity comes exclusively from the verified session; query/body IDs and roles are ignored.
require_once __DIR__ . '/class/Database.php';
$db = Database::getInstance()->getConnection();
$accounts = new CloudHubAccounts($db);
$account = $accounts->account((string)$_SESSION['steamid']);
if (!$account) { wp_json_error(409, 'Sign in through Steam again to initialize your CloudHub account'); }
if (($_GET['action'] ?? '') === 'history') {
    $before=$_GET['before']??null;
    if ($before!==null && !is_string($before)) { wp_json_error(422,'Invalid cursor'); }
    try { $history=$accounts->transactions((string)$_SESSION['steamid'],$before); }
    catch (InvalidArgumentException $e) { wp_json_error(422,'Invalid cursor'); }
    echo json_encode(['transactions'=>$history,'next_before'=>count($history)===50?end($history)['id']:null],JSON_THROW_ON_ERROR);
    exit;
}
// Ownership enforcement belongs to Stage 6 and stays off until the OWNER approves the migration.
$shop = CloudHubShop::active($db);
echo json_encode(['account'=>$account,'features'=>['shop'=>$shop,'shop_purchases'=>$shop && CloudHubShop::purchasesEnabled(),'ownership_enforcement'=>false]], JSON_THROW_ON_ERROR);
