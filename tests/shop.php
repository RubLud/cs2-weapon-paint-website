<?php
if (!defined('CLOUDHUB_SHOP_ENABLED')) define('CLOUDHUB_SHOP_ENABLED',true);
if (!defined('CLOUDHUB_SHOP_PURCHASES_ENABLED')) define('CLOUDHUB_SHOP_PURCHASES_ENABLED',true);
require_once __DIR__.'/../backend/class/Database.php';
require_once __DIR__.'/../backend/class/CloudHubShop.php';
$db=Database::getInstance()->getConnection();
if (!preg_match('/^cloudhub_test_[a-f0-9]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Disposable DB required');
$shop=new CloudHubShop($db); $accounts=new CloudHubAccounts($db); $n=0;
$check=function($ok,$message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;};
$reject=function($fn,$status)use($check){
    try{$fn();}
    catch(CloudHubShopError $e){$check($e->status===$status,'Expected status '.$status.', got '.$e->status);return;}
    catch(CloudHubForbidden){$check($status===403,'Unexpected forbidden');return;}
    catch(InvalidArgumentException){$check($status===422,'Unexpected validation error');return;}
    $check(false,'Expected rejection '.$status);
};
$a='76561198000000701';$b='76561198000000702';$owner='76561198000000001';
foreach([$a,$b] as $id)$accounts->register($id,'Shop fixture '.$id);
$uuid=fn(int $i)=>sprintf('20000000-0000-4000-8000-%012d',$i);
$wallet=fn($id)=>(int)$db->query("SELECT balance FROM cloudhub_wallets WHERE steamid='$id'")->fetchColumn();
$setWallet=fn($id,$v)=>$db->exec("UPDATE cloudhub_wallets SET balance=$v WHERE steamid='$id'");
$count=fn($sql)=>(int)$db->query($sql)->fetchColumn();
$check(CloudHubShop::active($db),'Shop active with flag and migration 004');

// Seeding: a small real slice of the bundled catalog.
$all=CloudHubShopPricing::catalog(wp_catalog('weapons.json'),wp_catalog('data/skins_en.json'),wp_catalog('data/gloves_en.json'));
$pick=function($fn)use($all){foreach($all as $p)if($fn($p))return $p;throw new RuntimeException('Fixture product missing');};
$subset=[
    $pick(fn($p)=>$p['kind']==='weapon_skin' && $p['weapon_defindex']===4),
    $pick(fn($p)=>$p['kind']==='weapon_skin' && $p['weapon_defindex']===7),
    $pick(fn($p)=>$p['kind']==='knife' && $p['paint_id']>0),
    $pick(fn($p)=>$p['kind']==='glove'),
    $pick(fn($p)=>$p['kind']==='weapon_skin' && $p['weapon_defindex']===9),
];
$dry=$shop->seed($subset,false,false);
$check($dry['new']===5 && !$dry['applied'] && $count('SELECT COUNT(*) FROM cloudhub_shop_products')===0,'Dry run writes nothing');
$check($shop->seed($subset,false,true)['new']===5,'Seed inserts proposals');
$check($count('SELECT COUNT(*) FROM cloudhub_shop_products WHERE is_active=1')===0,'Seeded products start inactive');
$check($count("SELECT COUNT(*) FROM cloudhub_shop_price_history WHERE actor_steamid IS NULL")===5,'Seed recorded in price history');
$ids=array_map(fn($p)=>(int)$db->query("SELECT id FROM cloudhub_shop_products WHERE weapon_defindex={$p['weapon_defindex']} AND paint_id={$p['paint_id']}")->fetchColumn(),$subset);
[$glock,$ak,$knife,$glove,$awp]=array_map('strval',$ids);
$check($shop->catalog($a,[])['total']===0,'Inactive products hidden from catalog');
$setWallet($a,500);
$reject(fn()=>$shop->buy($a,['product_id'=>$glock,'request_id'=>$uuid(1)]),404);
$check($wallet($a)===500,'Inactive purchase leaves wallet');

// OWNER pricing.
$edit=fn($id,$price,$active,$req,$actor=null)=>$shop->setProduct($actor??$owner,['product_id'=>$id,'price_cc'=>(string)$price,'is_active'=>(string)$active,'reason'=>'Approved test price','request_id'=>$uuid($req)]);
$reject(fn()=>$edit($glock,1,1,100,$a),403);
$check($edit($glock,120,1,101)['status']==='applied','OWNER activates and prices product');
$check($edit($glock,120,1,101)['status']==='duplicate','OWNER replay deduplicated');
$reject(fn()=>$edit($glock,121,1,101),409);
foreach(['0','-5','1.5','1000001','abc'] as $bad)$reject(fn()=>$shop->setProduct($owner,['product_id'=>$glock,'price_cc'=>$bad,'is_active'=>'1','reason'=>'x','request_id'=>$uuid(102)]),422);
$reject(fn()=>$shop->setProduct($owner,['product_id'=>$glock,'price_cc'=>'5','is_active'=>'1','reason'=>' ','request_id'=>$uuid(102)]),422);
$reject(fn()=>$shop->setProduct($owner,['product_id'=>$glock,'price_cc'=>'5','is_active'=>'2','reason'=>'x','request_id'=>$uuid(102)]),422);
$reject(fn()=>$shop->setProduct($owner,['product_id'=>'4000000','price_cc'=>'5','is_active'=>'1','reason'=>'x','request_id'=>$uuid(102)]),404);
foreach([[$ak,120,103],[$knife,1500,104],[$glove,120,105],[$awp,120,106]] as [$id,$price,$req])$edit($id,$price,1,$req);
$history=$shop->priceHistory($owner,$glock)['entries'];
$check(count($history)===2 && $history[0]['old_price_cc']===(int)$subset[0]['price_cc'] && $history[0]['new_price_cc']===120 && $history[0]['actor_steamid']===$owner,'Price history keeps old and new price');
$check($count("SELECT COUNT(*) FROM cloudhub_admin_audit_logs WHERE action='shop.product'")===5,'Each applied price change audited once');
$check($shop->seed($subset,true,true)['new']===0 && (int)$db->query("SELECT price_cc FROM cloudhub_shop_products WHERE id=$glock")->fetchColumn()===120,'Re-seeding never overwrites OWNER prices');
$listing=$shop->catalog($a,['kind'=>'weapon_skin','sort'=>'price_asc']);
$check($listing['total']===3 && !$listing['items'][0]['owned'] && $listing['purchases_enabled']===true,'Catalog lists active weapon skins');
$check($shop->catalog(null,[])['total']===5,'Anonymous catalog without ownership');
$check($shop->catalog($a,['search'=>'%'])['total']===0,'LIKE wildcards are escaped');
$check($shop->adminProducts($owner,['active'=>'1'])['total']===5,'OWNER listing with filters');
$reject(fn()=>$shop->adminProducts($a,[]),403);
foreach([['kind'=>'agent'],['sort'=>'evil'],['page'=>'0'],['weapon'=>'x'],['search'=>str_repeat('x',65)]] as $bad)$reject(fn()=>$shop->catalog($a,$bad),422);

// Purchases.
$skinsBefore=$db->query("SELECT * FROM wp_player_skins ORDER BY steamid,weapon_team,weapon_defindex")->fetchAll(PDO::FETCH_ASSOC);
$buy=$shop->buy($a,['product_id'=>$glock,'request_id'=>$uuid(1),'expected_price'=>'120']);
$check($buy['status']==='applied' && $buy['balance']==='380' && $buy['price_cc']===120,'Purchase applied with server price');
$tx=$db->query("SELECT amount,balance_before,balance_after,source FROM cloudhub_wallet_transactions WHERE operation_key='shop:$a:".$uuid(1)."'")->fetch(PDO::FETCH_ASSOC);
$check($tx && (int)$tx['amount']===-120 && (int)$tx['balance_before']===500 && (int)$tx['balance_after']===380 && $tx['source']==='shop','Ledger records before/after balance');
$check($shop->buy($a,['product_id'=>$glock,'request_id'=>$uuid(1)])['status']==='duplicate' && $wallet($a)===380,'Replay charges once');
$reject(fn()=>$shop->buy($a,['product_id'=>$ak,'request_id'=>$uuid(1)]),409);
$reject(fn()=>$shop->buy($a,['product_id'=>$glock,'request_id'=>$uuid(2)]),409);
$check($wallet($a)===380,'Owned item is not charged twice');
$reject(fn()=>$shop->buy($a,['product_id'=>$ak,'request_id'=>$uuid(3),'expected_price'=>'119']),409);
$reject(fn()=>$shop->buy($a,['product_id'=>$ak,'request_id'=>$uuid(3),'price'=>'1']),422);
$reject(fn()=>$shop->buy($a,['product_id'=>$ak,'request_id'=>$uuid(3),'steamid'=>$b]),422);
foreach(['0','abc','-1','1.0','99999999999'] as $bad)$reject(fn()=>$shop->buy($a,['product_id'=>$bad,'request_id'=>$uuid(3)]),422);
$reject(fn()=>$shop->buy($a,['product_id'=>$ak,'request_id'=>'not-a-uuid']),422);
$reject(fn()=>$shop->buy($a,['product_id'=>'3000000','request_id'=>$uuid(3)]),404);
$reject(fn()=>$shop->buy($a,['product_id'=>$knife,'request_id'=>$uuid(4)]),409);
$check($wallet($a)===380 && $count("SELECT COUNT(*) FROM cloudhub_shop_purchases WHERE steamid='$a'")===1,'Rejected purchases change nothing');
$reject(fn()=>$shop->buy('76561198000000799',['product_id'=>$ak,'request_id'=>$uuid(5)]),409);
$inv=$shop->inventory($a)['items'];
$check(count($inv)===1 && $inv[0]['id']===(int)$glock && $inv[0]['source']==='purchase','Inventory holds purchase');
$check($shop->inventory($b)['items']===[] && $shop->purchases($b,null)['items']===[],'Other player sees no foreign inventory or history');
$hist=$shop->purchases($a,null);
$check(count($hist['items'])===1 && $hist['items'][0]['price_cc']===120 && $hist['next_before']===null,'Purchase history');
$check(array_column(array_filter($shop->catalog($a,[])['items'],fn($i)=>$i['id']===(int)$glock),'owned')===[true],'Catalog marks owned item');
$check($skinsBefore===$db->query("SELECT * FROM wp_player_skins ORDER BY steamid,weapon_team,weapon_defindex")->fetchAll(PDO::FETCH_ASSOC),'Purchases never touch WeaponPaints configuration');
// Deactivated products stay owned.
$edit($glock,120,0,107);
$check(count($shop->inventory($a)['items'])===1,'Deactivation keeps ownership');
$edit($glock,120,1,108);

// Rollback: a failing last step leaves wallet, ledger and purchase untouched.
$db->exec("CREATE TRIGGER test_inventory_failure BEFORE INSERT ON cloudhub_player_inventory FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test rollback'");
try { try { $shop->buy($a,['product_id'=>$ak,'request_id'=>$uuid(6)]); $check(false,'Trigger must fail purchase'); } catch (PDOException) { $check(true,'Inventory failure aborts purchase'); } }
finally { $db->exec('DROP TRIGGER test_inventory_failure'); }
$check($wallet($a)===380 && $count("SELECT COUNT(*) FROM cloudhub_wallet_transactions WHERE operation_key='shop:$a:".$uuid(6)."'")===0 && $count("SELECT COUNT(*) FROM cloudhub_shop_purchases WHERE request_id='".$uuid(6)."'")===0,'Failed purchase fully rolled back');

// Independent connections.
$workers=function(array $inputs){
    $running=[];
    foreach($inputs as $in){$p=[];$proc=proc_open([PHP_BINARY,__DIR__.'/shop-worker.php',is_string($in)?$in:json_encode($in)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$p);fclose($p[0]);$running[]=[$proc,$p];}
    $out=[];
    foreach($running as [$proc,$p]){$json=stream_get_contents($p[1]);$err=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);if(proc_close($proc)!==0)throw new RuntimeException($err);$out[]=json_decode($json,true,512,JSON_THROW_ON_ERROR)['status'];}
    sort($out); return $out;
};
$check($workers(['disabled'])===[403],'Purchases flag fails closed');
$setWallet($a,150);
$check($workers([[$a,['product_id'=>$ak,'request_id'=>$uuid(7)]],[$a,['product_id'=>$glove,'request_id'=>$uuid(8)]]])===[409,'applied'],'Concurrent purchases cannot overspend');
$check($wallet($a)===30,'Concurrent purchases exact balance');
$setWallet($a,200);
$check($workers([[$a,['product_id'=>$awp,'request_id'=>$uuid(9)]],[$a,['product_id'=>$awp,'request_id'=>$uuid(9)]]])===['applied','duplicate'],'Concurrent replay applied once');
$check($wallet($a)===80,'Concurrent replay exact balance');
$setWallet($a,2000);
$check($workers([[$a,['product_id'=>$knife,'request_id'=>$uuid(10)]],[$a,['product_id'=>$knife,'request_id'=>$uuid(11)]]])===[409,'applied'],'Concurrent same-item purchases with different requests');
$check($wallet($a)===500 && $count("SELECT COUNT(*) FROM cloudhub_shop_purchases WHERE steamid='$a' AND product_id=$knife")===1,'Same item bought at most once');
$check($count("SELECT COUNT(*) FROM cloudhub_shop_purchases WHERE steamid='$a'")===$count("SELECT COUNT(*) FROM cloudhub_player_inventory WHERE steamid='$a'"),'Every purchase has exactly one inventory row');
$check($count("SELECT COUNT(*) FROM cloudhub_wallet_transactions WHERE steamid='$a' AND source='shop' AND balance_after<>balance_before+amount")===0,'Ledger arithmetic consistent');

// Economy statistics for OWNER.
$stats=$shop->stats($owner);
$check((int)$stats['purchases']===$count('SELECT COUNT(*) FROM cloudhub_shop_purchases') && (int)$stats['active_products']===5 && in_array('shop',array_column($stats['sources'],'source'),true),'OWNER economy statistics');
$reject(fn()=>$shop->stats($a),403);

// Missing migration degrades to disabled.
$db->exec("UPDATE cloudhub_schema_migrations SET version='004_hidden_for_test' WHERE version='004_cloudhub_shop.sql'");
try { $check(!CloudHubShop::active($db),'Missing migration 004 disables shop'); }
finally { $db->exec("UPDATE cloudhub_schema_migrations SET version='004_cloudhub_shop.sql' WHERE version='004_hidden_for_test'"); }

// Fixture cleanup; active products stay for the API/UI tests.
$db->exec("DELETE FROM cloudhub_player_inventory WHERE steamid LIKE '765611980000007%'");
$db->exec("DELETE FROM cloudhub_shop_purchases WHERE steamid LIKE '765611980000007%'");
$db->exec("DELETE FROM cloudhub_wallet_transactions WHERE steamid LIKE '765611980000007%'");
foreach(['cloudhub_player_roles','cloudhub_player_stats','cloudhub_wallets','cloudhub_profiles','cloudhub_players'] as $t)$db->exec("DELETE FROM $t WHERE steamid LIKE '765611980000007%'");
fwrite(STDERR,"CloudHub shop assertions passed: $n\n");
