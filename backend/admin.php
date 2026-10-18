<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/class/SessionBootstrap.php';
require_once __DIR__.'/class/ApiProtect.php';
require_once __DIR__.'/class/CloudHubAdmin.php';
require_once __DIR__.'/class/CloudHubShop.php';
wp_send_cors_headers(); wp_boot_session();
header('Content-Type: application/json; charset=utf-8');
if (!CloudHubAccounts::enabled()) wp_json_error(404,'CloudHub Core is disabled');
if (!isset($_SESSION['steamid'])) wp_json_error(401,'Not logged in');
$actor=(string)$_SESSION['steamid']; wp_enforce_rate_limit($actor);
$action=$_GET['action']??'players';
if (in_array($action,['adjust','shop_product'],true)) wp_require_post();
elseif (($_SERVER['REQUEST_METHOD']??'')!=='GET') { header('Allow: GET'); wp_json_error(405,'Use GET'); }
require_once __DIR__.'/class/Database.php';
$db=Database::getInstance()->getConnection();
$admin=new CloudHubAdmin($db);
try {
    $admin->requireOwner($actor);
    foreach ($_GET as $value) if (!is_string($value)) throw new InvalidArgumentException('Invalid parameter');
    if (str_starts_with((string)$action,'shop_') && !CloudHubShop::active($db)) throw new CloudHubShopError('Shop is disabled',404);
    $shop=new CloudHubShop($db);
    $result=match($action) {
        'players'=>$admin->players($actor,$_GET['search']??'',filter_var($_GET['page']??'1',FILTER_VALIDATE_INT)?:0),
        'audit'=>$admin->audit($actor,$_GET['before']??null),
        'config'=>$admin->config($actor),
        'adjust'=>$admin->adjust($actor,$_POST),
        'shop_products'=>$shop->adminProducts($actor,array_diff_key($_GET,['action'=>true])),
        'shop_history'=>$shop->priceHistory($actor,$_GET['product']??''),
        'shop_stats'=>$shop->stats($actor),
        'shop_product'=>$shop->setProduct($actor,$_POST),
        default=>throw new InvalidArgumentException('Unknown action'),
    };
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (CloudHubForbidden $e) { wp_json_error(403,$e->getMessage()); }
catch (CloudHubConflict $e) { wp_json_error(409,$e->getMessage()); }
catch (CloudHubShopError $e) { wp_json_error($e->status,$e->getMessage()); }
catch (InvalidArgumentException $e) { wp_json_error(422,$e->getMessage()); }
