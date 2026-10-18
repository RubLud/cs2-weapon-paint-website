<?php
/**
 * Deterministic price proposal and earning simulation. No database access.
 * Prices are proposals for OWNER review; the catalog has no rarity data, so tiers
 * use weapon class, knife model and glove family only. Every price stays editable.
 */
final class CloudHubShopPricing {
    private const WEAPON = [
        'ak47'=>400,'m4a1'=>400,'m4a1_silencer'=>400,'awp'=>600,
        'deagle'=>200,'usp_silencer'=>150,'glock'=>150,'hkp2000'=>120,
        'famas'=>150,'galilar'=>150,'aug'=>200,'sg556'=>200,'ssg08'=>200,
        'g3sg1'=>150,'scar20'=>150,'p90'=>150,'mp9'=>120,'mac10'=>120,
    ];
    private const CATEGORY = ['Rifle'=>250,'Sniper'=>200,'Pistol'=>100,'PM'=>100,'Shotgun'=>80,'Machine Gun'=>80];
    private const KNIFE_TIER = [
        'knife_karambit'=>2200,'knife_butterfly'=>2200,'knife_m9_bayonet'=>2200,
        'bayonet'=>1900,'knife_skeleton'=>1900,'knife_stiletto'=>1900,'knife_widowmaker'=>1900,'knife_ursus'=>1900,'knife_tactical'=>1800,'knife_flip'=>1800,
    ];
    private const KNIFE_DEFAULT = 1600;
    private const KNIFE_VANILLA = 1500;
    private const GLOVE_FAMILY = [5030=>2200,5034=>2000,5033=>1900,5031=>1800,5035=>1700,5027=>1700,4725=>1700,5032=>1600];

    /** Reward rules seeded by migration 002 (the values the backend applies today). */
    public const RULES_CURRENT = [
        'hourly_rp_cap'=>500,'hourly_cc_cap'=>100,'kill_rp'=>10,'kill_cc'=>2,'headshot_rp'=>3,'headshot_cc'=>1,
        'assist_rp'=>4,'assist_cc'=>1,'death_rp'=>-2,'round_win_rp'=>5,'round_win_cc'=>2,'round_play_rp'=>1,'round_play_cc'=>1,
    ];
    /** Starting values from the newer spec §13.1, to simulate only; MVP and round loss are not yet sent by the plugin. */
    public const RULES_SPEC_13_1 = ['kill_cc'=>3,'death_rp'=>-3,'mvp_rp'=>8,'mvp_cc'=>5,'round_loss_rp'=>-2] + self::RULES_CURRENT;

    public static function proposedPrice(string $kind, string $weapon, string $category, int $paint, int $defindex): int {
        return match($kind) {
            'weapon_skin'=>self::WEAPON[$weapon] ?? self::CATEGORY[$category] ?? throw new InvalidArgumentException('Unknown weapon class'),
            'knife'=>$paint===0 ? self::KNIFE_VANILLA : (self::KNIFE_TIER[$weapon] ?? self::KNIFE_DEFAULT),
            'glove'=>self::GLOVE_FAMILY[$defindex] ?? throw new InvalidArgumentException('Unknown glove family'),
            default=>throw new InvalidArgumentException('Unknown product kind'),
        };
    }

    /** Products derivable from the bundled WeaponPaints catalog. Default (paint 0) weapons are free and never sold. */
    public static function catalog(array $weapons, array $skins, array $gloves): array {
        $byId=[];
        foreach ($weapons as $w) if (is_int($w['cs2_id'] ?? null) && $w['cs2_id']>0) $byId[$w['cs2_id']]=$w;
        $out=[];
        foreach ($skins as $s) {
            $def=(int)$s['weapon_defindex']; $paint=(int)$s['paint']; $w=$byId[$def] ?? null;
            if ($w===null) continue;
            $kind=$w['category']==='Knife' ? 'knife' : 'weapon_skin';
            if ($kind==='weapon_skin' && $paint===0) continue;
            $out[]=['kind'=>$kind,'weapon_defindex'=>$def,'paint_id'=>$paint,'name'=>self::name($s['paint_name']),'price_cc'=>self::proposedPrice($kind,$w['name'],$w['category'],$paint,$def)];
        }
        foreach ($gloves as $g) {
            $def=(int)$g['weapon_defindex']; $paint=(int)$g['paint'];
            if ($def===0 || $paint===0) continue;
            $out[]=['kind'=>'glove','weapon_defindex'=>$def,'paint_id'=>$paint,'name'=>self::name($g['paint_name']),'price_cc'=>self::proposedPrice('glove','','',$paint,$def)];
        }
        return $out;
    }

    private static function name(string $name): string {
        $name=trim(preg_replace('/\s+/u',' ',$name) ?? '');
        if ($name==='' || preg_match('//u',$name)!==1 || preg_match_all('/./us',$name)>128) throw new InvalidArgumentException('Invalid catalog name');
        return $name;
    }

    /** One match per profile. Mirrors CloudHubRounds: positive totals are limited by the hourly caps; pair caps assume varied opponents. */
    public static function simulate(array $rules, array $p): array {
        $r=fn(string $k)=>(int)($rules[$k] ?? 0);
        $losses=$p['rounds']-$p['wins'];
        $rp=$p['kills']*$r('kill_rp')+$p['headshots']*$r('headshot_rp')+$p['assists']*$r('assist_rp')+$p['deaths']*$r('death_rp')
            +$p['wins']*$r('round_win_rp')+$p['rounds']*$r('round_play_rp')+$p['mvps']*$r('mvp_rp')+$losses*$r('round_loss_rp');
        $cc=$p['kills']*$r('kill_cc')+$p['headshots']*$r('headshot_cc')+$p['assists']*$r('assist_cc')
            +$p['wins']*$r('round_win_cc')+$p['rounds']*$r('round_play_cc')+$p['mvps']*$r('mvp_cc');
        $hours=$p['minutes']/60;
        $rpCap=(int)floor($r('hourly_rp_cap')*$hours); $ccCap=(int)floor($r('hourly_cc_cap')*$hours);
        return ['rp'=>min($rp,$rpCap),'cc'=>max(0,min($cc,$ccCap)),'rp_uncapped'=>$rp,'cc_uncapped'=>$cc];
    }

    /** Per-match profiles from spec §13.2, 24 rounds in about 40 minutes. */
    public static function profiles(): array {
        $p=fn($k,$hs,$a,$d,$w,$m)=>['rounds'=>24,'minutes'=>40,'kills'=>$k,'headshots'=>$hs,'assists'=>$a,'deaths'=>$d,'wins'=>$w,'mvps'=>$m];
        return [
            'weak'=>$p(8,2,3,20,10,0),'average'=>$p(16,6,4,17,12,2),'good'=>$p(22,10,5,14,13,4),'very_good'=>$p(30,15,5,11,15,7),
            'many_deaths'=>$p(12,4,4,23,11,1),'many_mvps'=>$p(20,8,4,15,14,8),'high_win_rate'=>$p(17,6,5,15,17,3),'low_win_rate'=>$p(15,5,4,18,7,1),
        ];
    }

    public static function matchesToBuy(int $price, int $ccPerMatch): ?int {
        return $ccPerMatch>0 ? intdiv($price+$ccPerMatch-1,$ccPerMatch) : null;
    }
}
