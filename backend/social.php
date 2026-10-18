<?php
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/class/SessionBootstrap.php';
require_once __DIR__.'/class/ApiProtect.php';
require_once __DIR__.'/class/CloudHubSocial.php';
wp_send_cors_headers(); wp_boot_session();
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
if (!CloudHubAccounts::enabled() || !CloudHubSocial::enabled()) wp_json_error(404,'Społeczność jest wyłączona.');
$actor=isset($_SESSION['steamid'])?(string)$_SESSION['steamid']:null;
wp_enforce_rate_limit($actor);
$action=$_GET['action']??'friends';
if (!is_string($action)) wp_json_error(422,'Nieprawidłowa operacja.');
$reads=['friends','comments','relation','connections','notifications','settings','moderation','audit','capabilities'];
$write=($_SERVER['REQUEST_METHOD']??'')==='POST';
if ($write) { if ($actor===null) wp_json_error(401,'Zaloguj się przez Steam.'); wp_require_post(); }
elseif (($_SERVER['REQUEST_METHOD']??'')!=='GET' || !in_array($action,$reads,true)) wp_json_error(405,'Nieprawidłowa metoda.');
if (!$write && !in_array($action,['friends','comments'],true) && $actor===null) wp_json_error(401,'Zaloguj się przez Steam.');
require_once __DIR__.'/class/Database.php';
$db=null;
try {
    $db=Database::getInstance()->getConnection(); $social=new CloudHubSocial($db);
    if (!CloudHubSocial::active($db)) throw new CloudHubSocialError('Społeczność nie jest jeszcze gotowa. Spróbuj ponownie później.',503);
    foreach ($_GET as $v) if (!is_string($v)) throw new InvalidArgumentException('Nieprawidłowy parametr.');
    if ($write) $result=$social->mutate($actor,$action,$_POST);
    else {
        // One consistent snapshot for access checks and their projected data.
        $db->beginTransaction();
        $page=filter_var($_GET['page']??'1',FILTER_VALIDATE_INT)?:0;
        $result=match($action) {
            'friends'=>$social->friendList($_GET['target']??$actor??'',$actor,$page),
            'comments'=>$social->comments($_GET['target']??'',$actor,$_GET['before']??null),
            'relation'=>$social->relation($actor,$_GET['target']??''),
            'connections'=>$social->connections($actor,$_GET['kind']??'received',$page),
            'notifications'=>$social->notifications($actor,$_GET['before']??null),
            'settings'=>$social->settings($actor),
            'capabilities'=>['moderate'=>$social->canModerate($actor)],
            'moderation'=>$social->moderation($actor,$_GET['before']??null),
            'audit'=>$social->auditList($actor,$_GET['before']??null),
        };
        $db->commit();
    }
    echo json_encode($result,JSON_THROW_ON_ERROR);
} catch (CloudHubSocialError $e) { if ($db?->inTransaction()) $db->rollBack(); if ($e->status===429) header('Retry-After: 60'); wp_json_error($e->status,$e->getMessage()); }
catch (InvalidArgumentException $e) { if ($db?->inTransaction()) $db->rollBack(); wp_json_error(422,$e->getMessage()); }
catch (Throwable $e) { if ($db?->inTransaction()) $db->rollBack(); error_log('CloudHub social request failed: '.get_class($e)); wp_json_error(503,'Operacja niedostępna. Spróbuj ponownie później.'); }
