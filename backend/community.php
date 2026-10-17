<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/class/SessionBootstrap.php';
require_once __DIR__.'/class/ApiProtect.php';
require_once __DIR__.'/class/CloudHubCommunity.php';
wp_send_cors_headers(); wp_boot_session();
header('Content-Type: application/json; charset=utf-8');
if (!CloudHubAccounts::enabled()) wp_json_error(404,'CloudHub Core is disabled');
$actor=isset($_SESSION['steamid'])?(string)$_SESSION['steamid']:null;
wp_enforce_rate_limit($actor);
$action=$_GET['action']??'leaderboard';
if (!is_string($action)) wp_json_error(422,'Invalid action');
$method=$_SERVER['REQUEST_METHOD']??'';
if ($action==='edit') {
    if ($actor===null) wp_json_error(401,'Not logged in');
    wp_require_post();
} elseif ($method!=='GET') { header('Allow: GET'); wp_json_error(405,'Use GET'); }
require_once __DIR__.'/class/Database.php';
$db=Database::getInstance()->getConnection();
$community=new CloudHubCommunity($db);
try {
    foreach ($_GET as $value) if (!is_string($value)) throw new InvalidArgumentException('Invalid parameter');
    if ($action!=='edit') $db->beginTransaction();
    $result=match ($action) {
        'leaderboard'=>$community->leaderboard($_GET['season']??null,$_GET['search']??'',$_GET['sort']??'rp',filter_var($_GET['limit']??'10',FILTER_VALIDATE_INT)?:0,filter_var($_GET['page']??'1',FILTER_VALIDATE_INT)?:0,false,$actor),
        'seasons'=>['seasons'=>$community->seasons()],
        'profile'=>['profile'=>$community->profile($_GET['steamid']??'', $actor)],
        'edit'=>(function () use ($community,$actor) { $community->edit($actor,$_POST); return ['saved'=>true]; })(),
        default=>throw new InvalidArgumentException('Unknown action'),
    };
    if ($db->inTransaction()) $db->commit();
    if ($action==='profile' && $result['profile']===null) wp_json_error(404,'Profile unavailable');
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (CloudHubSocialError $e) { if ($db->inTransaction()) $db->rollBack(); wp_json_error($e->status,$e->getMessage()); }
catch (InvalidArgumentException $e) { if ($db->inTransaction()) $db->rollBack(); wp_json_error(422,$e->getMessage()); }
