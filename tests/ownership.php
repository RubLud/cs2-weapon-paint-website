<?php
// Stage 6 ownership enforcement on the disposable database. Runs after tests/shop.php (active products exist).
if (!defined('CLOUDHUB_SHOP_ENABLED')) define('CLOUDHUB_SHOP_ENABLED',true);
if (!defined('CLOUDHUB_SHOP_PURCHASES_ENABLED')) define('CLOUDHUB_SHOP_PURCHASES_ENABLED',true);
if (!defined('CLOUDHUB_OWNERSHIP_ENFORCED')) define('CLOUDHUB_OWNERSHIP_ENFORCED',true);
require_once __DIR__.'/../backend/class/Database.php';
require_once __DIR__.'/../backend/class/CloudHubOwnership.php';
$db=Database::getInstance()->getConnection();
if (!preg_match('/^cloudhub_test_[a-f0-9]+$/D',(string)$db->query('SELECT DATABASE()')->fetchColumn())) throw new RuntimeException('Disposable DB required');
$own=new CloudHubOwnership($db); $accounts=new CloudHubAccounts($db); $n=0;
$check=function($ok,$message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;};
$count=fn($sql)=>(int)$db->query($sql)->fetchColumn();
$product=fn($kind)=>$db->query("SELECT id,weapon_defindex,paint_id FROM cloudhub_shop_products WHERE kind='$kind' AND is_active=1 AND paint_id>0 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
[$ak,$knife,$glove]=[$db->query("SELECT id,weapon_defindex,paint_id FROM cloudhub_shop_products WHERE weapon_defindex=7 AND is_active=1")->fetch(PDO::FETCH_ASSOC),$product('knife'),$product('glove')];
$glock=$db->query("SELECT id,weapon_defindex,paint_id FROM cloudhub_shop_products WHERE weapon_defindex=4 AND is_active=1")->fetch(PDO::FETCH_ASSOC);
$check($ak && $knife && $glove && $glock,'Fixture products from the shop test exist');
$item=fn($p)=>[(int)$p['weapon_defindex'],(int)$p['paint_id']];
$knifeName='weapon_'.array_values(array_filter(wp_catalog('weapons.json'),fn($w)=>($w['cs2_id'] ?? null)===(int)$knife['weapon_defindex']))[0]['name'];
// c: CloudHub player with WeaponPaints data. d: WeaponPaints-only player who never logged in. e: new player with nothing.
[$c,$d,$e]=['76561198000000801','76561198000000802','76561198000000803'];
foreach([$c,$e] as $id)$accounts->register($id,'Ownership fixture '.$id);
$skin=$db->prepare('INSERT INTO wp_player_skins(steamid,weapon_team,weapon_defindex,weapon_paint_id) VALUES (?,?,?,?)');
$skin->execute([$c,2,...$item($ak)]); $skin->execute([$c,3,7,99999]); $skin->execute([$c,2,(int)$knife['weapon_defindex'],0]);
$db->prepare('INSERT INTO wp_player_knife(steamid,weapon_team,knife) VALUES (?,2,?),(?,3,?)')->execute([$c,$knifeName,$c,'weapon_knife']);
$skin->execute([$d,2,...$item($glock)]);
$skin->execute([$d,3,...$item($glove)]); $db->prepare('INSERT INTO wp_player_gloves(steamid,weapon_team,weapon_defindex) VALUES (?,3,?)')->execute([$d,(int)$glove['weapon_defindex']]);
$payload=json_encode(['scope'=>'both','t'=>['skins'=>[['weapon_defindex'=>9,'weapon_paint_id'=>344]],'knife'=>'weapon_knife','gloves'=>null],'ct'=>['skins'=>[],'knife'=>null,'gloves'=>null]]);
$db->prepare("INSERT INTO wp_saved_loadouts(steamid,name,scope,weapon_team,thumb_url,payload,created_at,updated_at) VALUES (?,'Old preset','both',NULL,'',?,NOW(),NOW())")->execute([$d,$payload]);
$wpRows=fn()=>$count('SELECT (SELECT COUNT(*) FROM wp_player_skins)+(SELECT COUNT(*) FROM wp_player_knife)+(SELECT COUNT(*) FROM wp_player_gloves)+(SELECT COUNT(*) FROM wp_saved_loadouts)');
$wpBefore=$wpRows();

$check(CloudHubOwnership::installed($db) && $count("SELECT COUNT(*) FROM cloudhub_ownership_snapshots")===0,'Migration 005 installed without snapshot');
$check(!CloudHubOwnership::active($db),'No OWNER snapshot keeps enforcement off despite the flag');
$own->require($e,[$item($ak)]); $check(true,'Inactive enforcement never refuses');
$dry=$own->snapshot(false);
$check(!$dry['applied'] && $dry['players']>=2 && $count('SELECT COUNT(*) FROM cloudhub_legacy_ownership')===0 && $count('SELECT COUNT(*) FROM cloudhub_ownership_snapshots')===0,'Dry run writes nothing');
try { $own->snapshot(true,' '); $check(false,'Snapshot without reason'); } catch (InvalidArgumentException) { $check(true,'Snapshot requires a reason'); }
$snap=$own->snapshot(true,'Stage 6 test snapshot');
$check($snap['applied'] && $snap['added']===$snap['items'] && $snap['added']===$count('SELECT COUNT(*) FROM cloudhub_legacy_ownership'),'Snapshot records every collected item');
$check(CloudHubOwnership::active($db),'Flag, migrations and snapshot activate enforcement');
$again=$own->snapshot(true,'Repeat');
$check($again['added']===0 && $count('SELECT COUNT(*) FROM cloudhub_legacy_ownership')===$snap['added'],'Repeat snapshot is additive and idempotent');
$check($wpRows()===$wpBefore,'Snapshot leaves WeaponPaints tables untouched');

// Legacy: what players had stays usable, including combinations outside the catalog and wp-only players.
$check($own->missing($c,[$item($ak),[7,99999],[(int)$knife['weapon_defindex'],0]])===[],'Equipped skins, unknown finishes and knife model are grandfathered');
$check($own->missing($d,[$item($glock),[9,344],$item($glove),[(int)$glove['weapon_defindex'],0]])===[],'WeaponPaints-only player keeps equipped items, gloves and preset contents');
$check($count("SELECT COUNT(*) FROM cloudhub_legacy_ownership WHERE steamid='$d' AND origin='loadout'")===1,'Saved preset items recorded as loadout origin');

// Rules for a player without legacy items.
$check($own->missing($e,[[7,0],[0,0],[1,0]])===[],'Default finishes and default gloves stay free');
$check(CloudHubOwnership::knifeDefindex('weapon_knife')===null && CloudHubOwnership::knifeDefindex('weapon_knife_t')===null,'Default knives are free');
$check(CloudHubOwnership::knifeDefindex('weapon_knife_unknown')===-1 && count($own->missing($e,[[-1,0]]))===1,'Unknown knife refused');
$m=$own->missing($e,[$item($ak),$item($ak),[7,99999],[(int)$knife['weapon_defindex'],0],[(int)$glove['weapon_defindex'],0]]);
$check(count($m)===4 && $m[0]===['weapon_defindex'=>7,'paint_id'=>(int)$ak['paint_id']],'Unowned skin, unknown finish, knife and glove models refused once each');
try { $own->require($e,[$item($ak)]); $check(false,'require must throw'); }
catch (CloudHubOwnershipError $x) { $check($x->items===[['weapon_defindex'=>7,'paint_id'=>(int)$ak['paint_id']]],'require reports the missing items'); }

// Purchases grant ownership; a finished knife or glove unlocks its model, not other finishes.
$shop=new CloudHubShop($db);
$db->exec("UPDATE cloudhub_wallets SET balance=10000 WHERE steamid='$e'");
$uuid=fn(int $i)=>sprintf('60000000-0000-4000-8000-%012d',$i);
foreach([$ak,$knife,$glove] as $i=>$p) $check($shop->buy($e,['product_id'=>(string)$p['id'],'request_id'=>$uuid($i)])['status']==='applied','Fixture purchase '.$i);
$check($own->missing($e,[$item($ak),$item($knife),[(int)$knife['weapon_defindex'],0],$item($glove),[(int)$glove['weapon_defindex'],0]])===[],'Bought items and their models are allowed');
$check(count($own->missing($e,[[(int)$knife['weapon_defindex'],(int)$knife['paint_id']+100000]]))===1,'Owning one knife finish does not unlock others');

// Shop sees legacy ownership: owned badge, inventory entry, no double purchase.
$prices=$shop->prices($c)['items'];
$check(in_array([7,(int)$ak['paint_id'],null,true],array_map(fn($r)=>[$r[0],$r[1],null,$r[3]],$prices),true) && $shop->prices($c)['enforced']===true,'Configurator index marks legacy items owned and reports enforcement');
$inv=$shop->inventory($c)['items'];
$check(count(array_filter($inv,fn($p)=>$p['source']==='legacy' && $p['id']===(int)$ak['id']))===1,'Inventory lists legacy items');
$db->exec("UPDATE cloudhub_wallets SET balance=10000 WHERE steamid='$c'");
try { $shop->buy($c,['product_id'=>(string)$ak['id'],'request_id'=>$uuid(10)]); $check(false,'Legacy item bought twice'); }
catch (CloudHubShopError $x) { $check($x->status===409 && $count("SELECT balance FROM cloudhub_wallets WHERE steamid='$c'")===10000,'Legacy item cannot be bought again; wallet unchanged'); }

// Preset payload shapes.
$items=CloudHubOwnership::teamItems(['skins'=>[['weapon_defindex'=>7,'weapon_paint_id'=>5]],'knife'=>$knifeName,'gloves'=>['weapon_defindex'=>5030,'weapon_paint_id'=>10037]]);
$check($items===[[7,5],[(int)$knife['weapon_defindex'],0],[5030,10037]] && CloudHubOwnership::teamItems(['knife'=>'weapon_knife','skins'=>'x'])===[],'Preset items extracted, defaults and junk ignored');

// Read-only audit finds items picked in-game after the snapshot.
$skin->execute([$e,3,...$item($glock)]);
$before=$wpRows(); $legacy=$count('SELECT COUNT(*) FROM cloudhub_legacy_ownership');
$audit=$own->audit();
$hit=array_values(array_filter($audit['sample'],fn($r)=>$r['steamid']===$e));
$check($audit['players']>=1 && $hit && $hit[0]['items']===[['weapon_defindex'=>4,'paint_id'=>(int)$glock['paint_id']]],'Audit reports unowned equipped item');
$check($wpRows()===$before && $count('SELECT COUNT(*) FROM cloudhub_legacy_ownership')===$legacy,'Audit changes nothing');

// Database guard: the CS2 plugin writes wp_* directly (in-game menus). Statements below are the plugin's own SQL
// (Nereziel/cs2-WeaponPaints 6b1039d, WeaponSynchronization.cs) with its parameters.
$f='76561198000000804'; // WeaponPaints-only player, nothing owned
$db->exec("UPDATE cloudhub_schema_migrations SET version='004_hidden_for_test' WHERE version='004_cloudhub_shop.sql'");
try { $own->installGuard('Guard test'); $check(false,'Guard installed without active shop'); }
catch (RuntimeException) { $check($own->guardTriggers()===[],'Guard refuses to install while the shop is inactive'); }
finally { $db->exec("UPDATE cloudhub_schema_migrations SET version='004_cloudhub_shop.sql' WHERE version='004_hidden_for_test'"); }
$guard=$own->installGuard('Guard test');
$check(count($guard['triggers'])===6 && $guard['snapshot']['applied'],'Guard installs six triggers after a fresh snapshot');
$check(count($own->installGuard('Guard repeat')['triggers'])===6,'Guard install is repeatable');
$pluginSkin=function($id,$team,$def,$paint)use($db){
    $s=$db->prepare('SELECT COUNT(*) FROM `wp_player_skins` WHERE `steamid`=? AND `weapon_defindex`=? AND `weapon_team`=?'); $s->execute([$id,$def,$team]);
    $q=(int)$s->fetchColumn()>0
        ? 'UPDATE `wp_player_skins` SET `weapon_paint_id` = :paintId, `weapon_wear` = :wear, `weapon_seed` = :seed WHERE `steamid` = :steamid AND `weapon_defindex` = :weaponDefIndex AND `weapon_team` = :weaponTeam'
        : 'INSERT INTO `wp_player_skins` (`steamid`, `weapon_defindex`, `weapon_team`, `weapon_paint_id`, `weapon_wear`, `weapon_seed`) VALUES (:steamid, :weaponDefIndex, :weaponTeam, :paintId, :wear, :seed)';
    $db->prepare($q)->execute(['steamid'=>$id,'weaponDefIndex'=>$def,'weaponTeam'=>$team,'paintId'=>$paint,'wear'=>0.25,'seed'=>42]);
};
$pluginKnife=fn($id,$team,$knife)=>$db->prepare('INSERT INTO `wp_player_knife` (`steamid`, `weapon_team`, `knife`) VALUES(:steamid, :team, :newKnife) ON DUPLICATE KEY UPDATE `knife` = :newKnife2')->execute(['steamid'=>$id,'team'=>$team,'newKnife'=>$knife,'newKnife2'=>$knife]);
$pluginGlove=fn($id,$team,$def)=>$db->prepare('INSERT INTO `wp_player_gloves` (`steamid`, `weapon_team`, `weapon_defindex`) VALUES(:steamid, :team, :gloveDefIndex) ON DUPLICATE KEY UPDATE `weapon_defindex` = :gloveDefIndex2')->execute(['steamid'=>$id,'team'=>$team,'gloveDefIndex'=>$def,'gloveDefIndex2'=>$def]);
$paintOf=fn($id,$team,$def)=>$db->query("SELECT weapon_paint_id FROM wp_player_skins WHERE steamid='$id' AND weapon_team=$team AND weapon_defindex=$def")->fetchColumn();
$knifeOf=fn($id,$team)=>$db->query("SELECT knife FROM wp_player_knife WHERE steamid='$id' AND weapon_team=$team")->fetchColumn();
$gloveOf=fn($id,$team)=>$db->query("SELECT weapon_defindex FROM wp_player_gloves WHERE steamid='$id' AND weapon_team=$team")->fetchColumn();
$pluginSkin($f,2,7,(int)$ak['paint_id']);
$check((int)$paintOf($f,2,7)===0,'In-game pick of an unowned skin is stored as the default finish');
$pluginSkin($e,3,7,(int)$ak['paint_id']);
$check((int)$paintOf($e,3,7)===(int)$ak['paint_id'],'In-game pick of an owned skin is stored');
$pluginSkin($e,3,7,99999);
$check((int)$paintOf($e,3,7)===0,'Changing to an unowned finish via UPDATE is stored as the default');
$pluginSkin($e,3,7,(int)$ak['paint_id']); $pluginSkin($e,3,7,(int)$ak['paint_id']);
$check((int)$paintOf($e,3,7)===(int)$ak['paint_id'] && (float)$db->query("SELECT weapon_wear FROM wp_player_skins WHERE steamid='$e' AND weapon_team=3 AND weapon_defindex=7")->fetchColumn()===0.25,'Owned skin personalization through the plugin is free');
$pluginKnife($f,2,'weapon_knife_karambit');
$check($knifeOf($f,2)==='weapon_knife','Unowned knife model is stored as the default knife');
$pluginKnife($f,2,'weapon_knife_t'); $check($knifeOf($f,2)==='weapon_knife_t','Default knives pass');
$pluginKnife($f,3,'weapon_knife_not_real'); $check($knifeOf($f,3)==='weapon_knife','Unknown knife name is stored as the default knife');
$pluginKnife($e,2,$knifeName); $check($knifeOf($e,2)===$knifeName,'Knife model of an owned finish passes (insert)');
$other=$knifeName==='weapon_knife_karambit' ? 'weapon_bayonet' : 'weapon_knife_karambit';
$pluginKnife($e,2,$other); $check($knifeOf($e,2)==='weapon_knife','Switching to an unowned knife via ON DUPLICATE KEY UPDATE is refused');
$pluginGlove($f,3,(int)$glove['weapon_defindex']); $check((int)$gloveOf($f,3)===0,'Unowned glove model is stored as default gloves');
$pluginGlove($e,3,(int)$glove['weapon_defindex']); $check((int)$gloveOf($e,3)===(int)$glove['weapon_defindex'],'Owned glove model passes');
$pluginSkin($f,3,(int)$glove['weapon_defindex'],(int)$glove['paint_id']); $check((int)$paintOf($f,3,(int)$glove['weapon_defindex'])===0,'Unowned glove finish is stored as default');
$db->prepare('INSERT INTO `wp_player_music` (`steamid`, `weapon_team`, `music_id`) VALUES(?, 2, 3) ON DUPLICATE KEY UPDATE `music_id` = 3')->execute([$f]);
$check($count("SELECT COUNT(*) FROM wp_player_music WHERE steamid='$f' AND music_id=3")===1,'Music, agents and pins are not guarded');
// Rows equipped before the guard keep working: StatTrak/wear updates never take an item away.
$own->removeGuard();
$db->prepare('INSERT INTO wp_player_skins(steamid,weapon_team,weapon_defindex,weapon_paint_id) VALUES (?,2,9,344)')->execute([$f]);
$create=new ReflectionMethod(CloudHubOwnership::class,'guardSql');
foreach ($create->invoke(null) as $name=>$sql) $db->exec("CREATE TRIGGER $name $sql");
$db->prepare('UPDATE `wp_player_skins` SET `weapon_stattrak` = 1, `weapon_stattrak_count` = 5 WHERE `steamid` = ? AND `weapon_defindex` = 9 AND `weapon_team` = 2')->execute([$f]);
$check((int)$paintOf($f,2,9)===344 && $count("SELECT weapon_stattrak_count FROM wp_player_skins WHERE steamid='$f' AND weapon_defindex=9")===5,'StatTrak update keeps an item equipped before the guard');
$pluginSkin($f,2,9,344); $check((int)$paintOf($f,2,9)===344,'Re-saving the same item keeps it');
$audit=$own->audit(); $hit=array_values(array_filter($audit['sample'],fn($r)=>$r['steamid']===$f));
$check($hit && in_array(['weapon_defindex'=>9,'paint_id'=>344],$hit[0]['items'],true),'Audit reports the item equipped before the guard');
$pluginSkin($f,2,9,1); $check((int)$paintOf($f,2,9)===0,'Changing it to another unowned finish is refused');
$removed=$own->removeGuard();
$check($removed['triggers']===[] && (int)$paintOf($e,3,7)===(int)$ak['paint_id'],'Guard removal drops triggers and keeps stored equipment');
$pluginSkin($f,2,7,(int)$ak['paint_id']); $check((int)$paintOf($f,2,7)===(int)$ak['paint_id'],'Without the guard WeaponPaints writes work as before');

// Without migration 005 enforcement is off and the shop works on purchases alone.
$db->exec("UPDATE cloudhub_schema_migrations SET version='005_hidden_for_test' WHERE version='005_cloudhub_ownership.sql'");
try {
    $check(!CloudHubOwnership::active($db),'Missing migration 005 disables enforcement');
    $check(!array_filter((new CloudHubShop($db))->prices($c)['items'],fn($r)=>$r[3]),'Shop ignores legacy rows without migration 005');
} finally { $db->exec("UPDATE cloudhub_schema_migrations SET version='005_cloudhub_ownership.sql' WHERE version='005_hidden_for_test'"); }

// Cleanup of fixture players. The snapshot stays so the enforced API/UI tests run with enforcement active.
foreach(['wp_player_skins','wp_player_knife','wp_player_gloves','wp_player_music','wp_saved_loadouts'] as $t)$db->exec("DELETE FROM $t WHERE steamid LIKE '765611980000008%'");
$db->exec("DELETE FROM cloudhub_legacy_ownership WHERE steamid LIKE '765611980000008%'");
$db->exec("DELETE FROM cloudhub_player_inventory WHERE steamid LIKE '765611980000008%'");
$db->exec("DELETE FROM cloudhub_shop_purchases WHERE steamid LIKE '765611980000008%'");
$db->exec("DELETE FROM cloudhub_wallet_transactions WHERE steamid LIKE '765611980000008%'");
foreach(['cloudhub_player_roles','cloudhub_player_stats','cloudhub_wallets','cloudhub_profiles','cloudhub_players'] as $t)$db->exec("DELETE FROM $t WHERE steamid LIKE '765611980000008%'");
fwrite(STDERR,"CloudHub ownership assertions passed: $n\n");
