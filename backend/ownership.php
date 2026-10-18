<?php
// Read-only ownership view for the CS2 server's in-game WeaponPaints menus. Same server keys as ingest.php.
if (!defined('DB_AUTO_MIGRATE')) { define('DB_AUTO_MIGRATE',false); }
require_once __DIR__.'/bootstrap.php';
if (!defined('CLOUDHUB_CORE_ENABLED') || CLOUDHUB_CORE_ENABLED!==true || !defined('CLOUDHUB_OWNERSHIP_API_ENABLED') || CLOUDHUB_OWNERSHIP_API_ENABLED!==true) { wp_json_error(404,'Not found'); }
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { header('Allow: POST'); wp_json_error(405,'POST required'); }
if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') { wp_json_error(415,'JSON required'); }
if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) { wp_json_error(413,'Request too large'); }
$body=file_get_contents('php://input',false,null,0,8193);
if ($body===false || strlen($body)>8192) { wp_json_error(413,'Request too large'); }
require_once __DIR__.'/class/CloudHubIngestAuth.php';
$keys=defined('CLOUDHUB_INGEST_KEYS')?CLOUDHUB_INGEST_KEYS:[];
try {
    $server=CloudHubIngestAuth::verify(['server'=>$_SERVER['HTTP_X_CLOUDHUB_SERVER']??'', 'timestamp'=>$_SERVER['HTTP_X_CLOUDHUB_TIMESTAMP']??'', 'signature'=>$_SERVER['HTTP_X_CLOUDHUB_SIGNATURE']??''],$body,$keys,time());
} catch (InvalidArgumentException $e) { wp_json_error(401,'Server authentication required'); }
try { $input=json_decode($body,true,4,JSON_THROW_ON_ERROR); } catch (JsonException $e) { wp_json_error(422,'Invalid ownership request'); }
$ids=is_array($input) ? ($input['steamids']??null) : null;
if (!is_array($input) || !is_string($input['request']??null) || !preg_match('/^[a-f0-9]{32}$/D',$input['request']) ||
    !is_array($ids) || !array_is_list($ids) || count($ids)>64 || !is_bool($input['catalog']??null) ||
    array_filter($ids,fn($id)=>!is_string($id) || !preg_match('/^7656119[0-9]{10}$/D',$id)) || count(array_unique($ids))!==count($ids)) {
    wp_json_error(422,'Invalid ownership request');
}
// No Steam session, cookie or role is involved: the server key only reads ownership, it never grants anything.
require_once __DIR__.'/class/Database.php';
require_once __DIR__.'/class/CloudHubOwnership.php';
$view=(new CloudHubOwnership(Database::getInstance()->getConnection()))->serverView($ids,$input['catalog']);
$reply=json_encode(['v'=>1,'request'=>$input['request'],'server'=>$server,'generated_at'=>time()]+$view,JSON_THROW_ON_ERROR);
$stamp=(string)time();
header('Content-Type: application/json');
header('X-CloudHub-Timestamp: '.$stamp);
header('X-CloudHub-Signature: '.CloudHubIngestAuth::responseSignature($keys[$server],$server,$stamp,$reply));
echo $reply;
