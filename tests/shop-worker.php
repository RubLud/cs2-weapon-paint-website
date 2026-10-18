<?php
// Independent connection for concurrency tests. 'disabled' runs with purchases switched off.
if (($argv[1]??'')==='disabled') define('CLOUDHUB_SHOP_PURCHASES_ENABLED',false);
if (!defined('CLOUDHUB_SHOP_ENABLED')) define('CLOUDHUB_SHOP_ENABLED',true);
if (!defined('CLOUDHUB_SHOP_PURCHASES_ENABLED')) define('CLOUDHUB_SHOP_PURCHASES_ENABLED',true);
require_once __DIR__.'/../backend/class/Database.php';
require_once __DIR__.'/../backend/class/CloudHubShop.php';
$db=Database::getInstance()->getConnection();
if (!preg_match('/^cloudhub_test_[a-f0-9]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) exit(1);
[$actor,$input]=($argv[1]??'')==='disabled' ? ['76561198000000701',['product_id'=>'1','request_id'=>'20000000-0000-4000-8000-0000000000ff']] : json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
try { $r=(new CloudHubShop($db))->buy($actor,$input); echo json_encode(['status'=>$r['status']]); }
catch (CloudHubShopError $e) { echo json_encode(['status'=>$e->status]); }
