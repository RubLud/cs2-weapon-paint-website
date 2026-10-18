<?php
require_once __DIR__.'/../backend/class/Database.php';
require_once __DIR__.'/../backend/class/CloudHubSocial.php';
$db=Database::getInstance()->getConnection();
if (!preg_match('/^cloudhub_test_[a-f0-9]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Disposable DB required');
[$actor,$action,$input]=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
try { echo json_encode(['status'=>200,'result'=>(new CloudHubSocial($db))->mutate($actor,$action,$input)]); }
catch (CloudHubSocialError $e) { echo json_encode(['status'=>$e->status]); }
