<?php
if (($argv[1]??'')==='disabled') define('CLOUDHUB_ADMIN_CC_ENABLED',false);
require __DIR__.'/../backend/class/Database.php';
require __DIR__.'/../backend/class/CloudHubAdmin.php';
$db=Database::getInstance()->getConnection();
if (!preg_match('/^cloudhub_test_[a-f0-9]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) exit(1);
if (($argv[1]??'')==='disabled') {
    try { (new CloudHubAdmin($db))->adjust('76561198000000001',[]); exit(1); }
    catch (CloudHubForbidden $e) { echo 'disabled'; exit; }
}
try { echo json_encode((new CloudHubAdmin($db))->adjust('76561198000000001',json_decode($argv[1],true,512,JSON_THROW_ON_ERROR))); }
catch (CloudHubConflict $e) { echo json_encode(['status'=>'conflict']); }
