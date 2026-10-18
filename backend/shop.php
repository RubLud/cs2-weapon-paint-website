<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/class/SessionBootstrap.php';
require_once __DIR__.'/class/ApiProtect.php';
require_once __DIR__.'/class/CloudHubShop.php';
wp_send_cors_headers(); wp_boot_session();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!CloudHubAccounts::enabled() || !CloudHubShop::enabled()) wp_json_error(404,'Sklep jest wyłączony.');
$actor=isset($_SESSION['steamid'])?(string)$_SESSION['steamid']:null;
wp_enforce_rate_limit($actor);
$action=$_GET['action']??'catalog';
if (!is_string($action)) wp_json_error(422,'Nieprawidłowa operacja.');
$write=($_SERVER['REQUEST_METHOD']??'')==='POST';
if ($write) {
    if ($action!=='buy') wp_json_error(405,'Nieprawidłowa metoda.');
    if ($actor===null) wp_json_error(401,'Zaloguj się przez Steam.');
    wp_require_post();
} elseif (($_SERVER['REQUEST_METHOD']??'')!=='GET' || !in_array($action,['catalog','prices','inventory','purchases'],true)) wp_json_error(405,'Nieprawidłowa metoda.');
if (!in_array($action,['catalog','prices'],true) && $actor===null) wp_json_error(401,'Zaloguj się przez Steam.');
require_once __DIR__.'/class/Database.php';
$db=null;
try {
    $db=Database::getInstance()->getConnection(); $shop=new CloudHubShop($db);
    if (!CloudHubShop::active($db)) throw new CloudHubShopError('Sklep nie jest jeszcze gotowy. Spróbuj ponownie później.',503);
    foreach ($_GET as $v) if (!is_string($v)) throw new InvalidArgumentException('Nieprawidłowy parametr.');
    $query=array_diff_key($_GET,['action'=>true]);
    $result=match($action) {
        'buy'=>$shop->buy($actor,$_POST),
        'catalog'=>$shop->catalog($actor,$query),
        'prices'=>$shop->prices($actor),
        'inventory'=>$shop->inventory($actor),
        'purchases'=>$shop->purchases($actor,$_GET['before']??null),
    };
    if ($write && $actor!==null) wp_api_bust($actor);
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (CloudHubShopError $e) { if ($db?->inTransaction()) $db->rollBack(); wp_json_error($e->status,$e->getMessage()); }
catch (InvalidArgumentException $e) { if ($db?->inTransaction()) $db->rollBack(); wp_json_error(422,$e->getMessage()); }
catch (Throwable $e) { if ($db?->inTransaction()) $db->rollBack(); error_log('CloudHub shop request failed: '.get_class($e)); wp_json_error(503,'Operacja niedostępna. Spróbuj ponownie później.'); }
