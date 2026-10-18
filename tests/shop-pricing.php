<?php
// Pure pricing/simulation checks; no database, safe to run anywhere.
require_once __DIR__.'/../backend/class/Security.php';
require_once __DIR__.'/../backend/class/CloudHubShopPricing.php';
$n=0;
$check=function(bool $ok,string $message)use(&$n){if(!$ok)throw new RuntimeException($message);$n++;};
$weapons=wp_catalog('weapons.json'); $skins=wp_catalog('data/skins_en.json'); $gloves=wp_catalog('data/gloves_en.json');
$products=CloudHubShopPricing::catalog($weapons,$skins,$gloves);
$keys=array_map(fn($p)=>$p['weapon_defindex'].':'.$p['paint_id'],$products);
$check(count($keys)===count(array_unique($keys)),'One product per weapon and paint');
$byKind=[]; foreach($products as $p) $byKind[$p['kind']][]=$p;
$check(isset($byKind['weapon_skin'],$byKind['knife'],$byKind['glove']),'Weapon skins, knives and gloves are sold');
$knives=array_column(array_filter($weapons,fn($w)=>$w['category']==='Knife' && is_int($w['cs2_id'])),'cs2_id');
$check(!array_filter($byKind['weapon_skin'],fn($p)=>$p['paint_id']===0),'Default weapon finishes stay free');
$check(!array_filter($byKind['weapon_skin'],fn($p)=>in_array($p['weapon_defindex'],$knives,true)),'Knife skins are knife products');
$check(count(array_filter($byKind['knife'],fn($p)=>$p['paint_id']===0))>0,'Vanilla knife models are sold');
$check(!array_filter($byKind['glove'],fn($p)=>$p['weapon_defindex']===0 || $p['paint_id']===0),'Default gloves stay free');
$check(!array_filter($byKind['weapon_skin'],fn($p)=>$p['price_cc']<50 || $p['price_cc']>1100),'Weapon skin prices within proposed tiers');
$check(!array_filter(array_merge($byKind['knife'],$byKind['glove']),fn($p)=>$p['price_cc']<1500 || $p['price_cc']>4000),'Knife and glove prices within 1500-4000');
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
$reject(fn()=>CloudHubShopPricing::proposedPrice('agent','x','Other',1,1),'Unknown kind rejected');
$reject(fn()=>CloudHubShopPricing::proposedPrice('glove','','',1,1),'Unknown glove family rejected');
$reject(fn()=>CloudHubShopPricing::proposedPrice('weapon_skin','x','Other',1,1),'Unknown weapon class rejected');
fwrite(STDERR,"CloudHub shop pricing assertions passed: $n\n");
