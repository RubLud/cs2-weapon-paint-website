<?php
// Pure pricing/simulation checks; no database, safe to run anywhere.
require_once __DIR__.'/../backend/class/Security.php';
require_once __DIR__.'/../backend/class/CloudHubShopPricing.php';
$n=0;
$check=function(bool $ok,string $message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;};
$weapons=wp_catalog('weapons.json'); $skins=wp_catalog('data/skins_en.json'); $gloves=wp_catalog('data/gloves_en.json'); $rarity=wp_catalog('data/shop_rarity.json');
$products=CloudHubShopPricing::catalog($weapons,$skins,$gloves,$rarity);
$check(str_contains($rarity['source'],'ByMykel/CSGO-API') && str_contains($rarity['license'],'MIT') && preg_match('/^[a-f0-9]{64}$/D',$rarity['sha256'])===1,'Rarity snapshot keeps source, license and checksum');
$find=function(int $def,int $paint)use($products){foreach($products as $p)if($p['weapon_defindex']===$def&&$p['paint_id']===$paint)return $p;throw new RuntimeException("Missing product $def:$paint");};
$keys=array_map(fn($p)=>$p['weapon_defindex'].':'.$p['paint_id'],$products);
$check(count($keys)===count(array_unique($keys)),'One product per weapon and paint');
$byKind=[]; foreach($products as $p) $byKind[$p['kind']][]=$p;
$check(isset($byKind['weapon_skin'],$byKind['knife'],$byKind['glove']),'Weapon skins, knives and gloves are sold');
$knives=array_column(array_filter($weapons,fn($w)=>$w['category']==='Knife' && is_int($w['cs2_id'])),'cs2_id');
$check(!array_filter($byKind['weapon_skin'],fn($p)=>$p['paint_id']===0),'Default weapon finishes stay free');
$check(!array_filter($byKind['weapon_skin'],fn($p)=>in_array($p['weapon_defindex'],$knives,true)),'Knife skins are knife products');
$check(count(array_filter($byKind['knife'],fn($p)=>$p['paint_id']===0))>0,'Vanilla knife models are sold');
$check(!array_filter($byKind['glove'],fn($p)=>$p['weapon_defindex']===0 || $p['paint_id']===0),'Default gloves stay free');
$priced=fn($list)=>array_filter($list,fn($p)=>$p['price_cc']!==null);
$check(!array_filter($priced($byKind['weapon_skin']),fn($p)=>$p['price_cc']<50 || $p['price_cc']>2500),'Weapon skin prices within 50-2500');
$check(count(array_filter($products,fn($p)=>$p['needs_review']))===1,'Only the contraband item needs review in the bundled catalog');
$check(!array_filter($priced(array_merge($byKind['knife'],$byKind['glove'])),fn($p)=>$p['price_cc']<1500 || $p['price_cc']>CloudHubShopPricing::MAX_PRICE),'Knife and glove prices within 1500-5000');
$check(!array_filter($products,fn($p)=>$p['paint_id']>0 && $p['rarity']===null),'Every finished product has official rarity');
$check(!array_filter($products,fn($p)=>$p['price_cc']===null && !$p['needs_review']),'Unpriced products are always flagged for review');
$check(!array_filter($products,fn($p)=>$p['needs_review'] && $p['review_reason']===null),'Review flag carries a reason');
$howl=$find(16,309);
$check($howl['rarity']==='contraband' && $howl['needs_review'] && $howl['price_cc']===null,'M4A4 | Howl stays unsold and unpriced');
// Same weapon, higher official grade, higher price.
$ak=array_filter($byKind['weapon_skin'],fn($p)=>$p['weapon_defindex']===7);
$avgBy=function($rar)use($ak){$x=array_column(array_filter($ak,fn($p)=>$p['rarity']===$rar),'price_cc');return $x?array_sum($x)/count($x):null;};
$check($avgBy('mil_spec')<$avgBy('restricted') && $avgBy('restricted')<$avgBy('classified') && $avgBy('classified')<$avgBy('covert'),'Price follows rarity grade');
// Doppler phases are separate paint indexes and priced individually (Karambit 507).
[$p1,$p2,$bp,$ruby,$em]=[$find(507,418),$find(507,419),$find(507,417),$find(507,415),$find(507,568)];
$check($p1['phase']==='Phase 1' && $ruby['phase']==='Ruby' && $em['phase']==='Emerald','Doppler phases recognised from game data');
$check($p1['price_cc']<$p2['price_cc'] && $p2['price_cc']<$bp['price_cc'] && $bp['price_cc']<$ruby['price_cc'] && $ruby['price_cc']<$em['price_cc'],'Rare phases cost more');
$check($find(507,0)['price_cc']===1500 && !$find(507,0)['needs_review'],'Vanilla knife has floor price');
$check(count(array_unique(array_column($byKind['weapon_skin'],'price_cc')))>=5,'Weapon prices are differentiated');
$check(count(array_unique(array_column($byKind['knife'],'price_cc')))>=3,'Knife prices are differentiated');
foreach($skins as $s) if ((int)$s['paint']>0) {
    $ok=false; foreach($products as $p) if($p['weapon_defindex']===(int)$s['weapon_defindex'] && $p['paint_id']===(int)$s['paint']){$ok=true;break;}
    if(!$ok) throw new RuntimeException('Catalog skin missing from shop: '.$s['paint_name']);
}
$check(true,'Every painted catalog skin has a product');
$check(!array_filter($products,fn($p)=>preg_match('//u',$p['name'])!==1 || $p['name']===''),'Valid product names');
$rules=CloudHubShopPricing::RULES_CURRENT;
$avg=CloudHubShopPricing::profiles()['average'];
$sim=CloudHubShopPricing::simulate($rules,$avg);
$check($sim['cc']===66 && $sim['cc_uncapped']===90,'Average player CC per match, hourly cap applied');
$check($sim['rp']===244,'Average player RP per match');
$deaths=CloudHubShopPricing::simulate($rules,['deaths'=>40]+$avg);
$check($deaths['cc']===$sim['cc'] && $deaths['cc_uncapped']===$sim['cc_uncapped'],'Deaths never reduce Cloud Coins');
$spec=CloudHubShopPricing::RULES_SPEC_13_1;
$loss=CloudHubShopPricing::simulate($spec,['wins'=>0]+$avg);
$check($loss['cc']>=0 && $loss['cc_uncapped']===CloudHubShopPricing::simulate($spec,['wins'=>0,'mvps'=>0]+$avg)['cc_uncapped']+$avg['mvps']*5,'Lost rounds never reduce Cloud Coins');
foreach(CloudHubShopPricing::profiles() as $name=>$profile) {
    $check(CloudHubShopPricing::simulate($spec,$profile)['rp']>0,'Spec 13.1 profile still gains RP: '.$name);
    $check(CloudHubShopPricing::simulate($rules,$profile)['rp']>0,'Current rules profile gains RP: '.$name);
}
$check(CloudHubShopPricing::matchesToBuy(100,66)===2 && CloudHubShopPricing::matchesToBuy(2000,66)===31 && CloudHubShopPricing::matchesToBuy(5,0)===null,'Matches to buy');
$reject=function(callable $fn,string $message)use($check){try{$fn();}catch(InvalidArgumentException){$check(true,$message);return;}$check(false,$message);};
$meta=['rarity'=>'covert','phase'=>null];
$reject(fn()=>CloudHubShopPricing::propose('agent','x',1,1,$meta),'Unknown kind rejected');
$unknown=CloudHubShopPricing::propose('weapon_skin','ak47',7,99999,null);
$check($unknown['price_cc']===null && $unknown['needs_review'],'Missing rarity data gives no price and review');
$check(CloudHubShopPricing::propose('knife','knife_karambit',507,9999,['rarity'=>'covert','phase'=>'Phase 9'])['needs_review'],'Unknown phase needs review');
$check(CloudHubShopPricing::propose('glove','',1,1,['rarity'=>'extraordinary','phase'=>null])['needs_review'],'Unknown glove family needs review');
$check(CloudHubShopPricing::propose('knife','bayonet',500,1,['rarity'=>'classified','phase'=>null])['needs_review'],'Unexpected knife grade needs review');
// CloudHubSchema splits migrations on ';', so a semicolon inside a comment breaks the statement.
foreach (glob(__DIR__.'/../migrations/*.sql') as $file) foreach (file($file) as $i=>$line) if (str_starts_with(ltrim($line),'--') && str_contains($line,';')) throw new RuntimeException(basename($file).':'.($i+1).' comment contains ;');
$check(true,'Migration comments contain no statement separator');
fwrite(STDERR,"CloudHub shop pricing assertions passed: $n\n");
