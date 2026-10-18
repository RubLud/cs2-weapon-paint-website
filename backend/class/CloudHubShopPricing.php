<?php
/**
 * Deterministic price proposal and earning simulation. No database access.
 * Rarity and Doppler phase come from shop_rarity.json (pinned ByMykel/CSGO-API snapshot of CS2 game data).
 * Items without reliable data get no price and are flagged for OWNER review. Every price stays editable.
 * Float, pattern seed, StatTrak and stickers are free personalization (spec §10) and never affect the price.
 */
final class CloudHubShopPricing {
    /** Official grades of the specific finish, not the weapon model. */
    public const RARITY = ['consumer'=>50,'industrial'=>80,'mil_spec'=>120,'restricted'=>250,'classified'=>500,'covert'=>1000,'contraband'=>2500];
    /** Most played weapons: a small, documented premium; never applied to knives or gloves. */
    private const POPULAR = [7=>1.2,16=>1.2,60=>1.2,9=>1.2,1=>1.2,61=>1.2,4=>1.2];
    /** Knife model base for finished knives; a vanilla knife (paint 0) costs the floor price. */
    private const KNIFE_MODEL = [
        'knife_karambit'=>2200,'knife_butterfly'=>2200,'knife_m9_bayonet'=>2200,
        'bayonet'=>1900,'knife_skeleton'=>1900,'knife_stiletto'=>1900,'knife_widowmaker'=>1900,'knife_ursus'=>1900,'knife_tactical'=>1800,'knife_flip'=>1800,
    ];
    private const KNIFE_DEFAULT = 1600;
    private const KNIFE_VANILLA = 1500;
    private const GLOVE_FAMILY = [5030=>2200,5034=>2000,5033=>1900,5031=>1800,5035=>1700,5027=>1700,4725=>1700,5032=>1600];
    /** Phases are separate paint indexes in game data, so each phase is its own product. */
    private const PHASE_PREMIUM = ['Phase 1'=>200,'Phase 3'=>200,'Phase 2'=>400,'Phase 4'=>400,'Black Pearl'=>1000,'Ruby'=>1400,'Sapphire'=>1400,'Emerald'=>1600];
    public const MAX_PRICE = 5000;

    /** Reward rules seeded by migration 002 (the values the backend applies today). */
    public const RULES_CURRENT = [
        'hourly_rp_cap'=>500,'hourly_cc_cap'=>100,'kill_rp'=>10,'kill_cc'=>2,'headshot_rp'=>3,'headshot_cc'=>1,
        'assist_rp'=>4,'assist_cc'=>1,'death_rp'=>-2,'round_win_rp'=>5,'round_win_cc'=>2,'round_play_rp'=>1,'round_play_cc'=>1,
    ];
    /** Starting values from the newer spec §13.1, to simulate only; MVP and round loss are not yet sent by the plugin. */
    public const RULES_SPEC_13_1 = ['kill_cc'=>3,'death_rp'=>-3,'mvp_rp'=>8,'mvp_cc'=>5,'round_loss_rp'=>-2] + self::RULES_CURRENT;

    private static function round10(float $v): int { return (int)(round($v/10)*10); }

    /** @return array{price_cc:?int,needs_review:bool,review_reason:?string} */
    public static function propose(string $kind, string $weapon, int $defindex, int $paint, ?array $meta): array {
        $review=fn(string $why)=>['price_cc'=>null,'needs_review'=>true,'review_reason'=>$why];
        if ($kind==='knife' && $paint===0) return ['price_cc'=>self::KNIFE_VANILLA,'needs_review'=>false,'review_reason'=>null];
        if ($meta===null) return $review('Brak danych o rzadkości w katalogu gry');
        $phase=$meta['phase'];
        if ($phase!==null && !isset(self::PHASE_PREMIUM[$phase])) return $review('Nieznana faza: '.$phase);
        if ($kind==='weapon_skin') {
            if ($meta['rarity']==='contraband') return ['price_cc'=>self::RARITY['contraband'],'needs_review'=>true,'review_reason'=>'Contraband: przedmiot wycofany, wymaga decyzji OWNER'];
            if (!isset(self::RARITY[$meta['rarity']])) return $review('Nietypowa rzadkość broni: '.$meta['rarity']);
            $price=self::RARITY[$meta['rarity']]*(self::POPULAR[$defindex] ?? 1);
            if ($phase==='Emerald') $price*=1.5;
            return ['price_cc'=>min(self::MAX_PRICE,self::round10($price)),'needs_review'=>false,'review_reason'=>null];
        }
        if ($kind==='knife') {
            if ($meta['rarity']!=='covert') return $review('Nietypowa rzadkość noża: '.$meta['rarity']);
            $price=(self::KNIFE_MODEL[$weapon] ?? self::KNIFE_DEFAULT)+($phase!==null ? self::PHASE_PREMIUM[$phase] : 0);
            return ['price_cc'=>min(self::MAX_PRICE,$price),'needs_review'=>false,'review_reason'=>null];
        }
        if ($kind==='glove') {
            if ($meta['rarity']!=='extraordinary') return $review('Nietypowa rzadkość rękawic: '.$meta['rarity']);
            if (!isset(self::GLOVE_FAMILY[$defindex])) return $review('Nieznana rodzina rękawic');
            return ['price_cc'=>self::GLOVE_FAMILY[$defindex],'needs_review'=>false,'review_reason'=>null];
        }
        throw new InvalidArgumentException('Unknown product kind');
    }

    /** Products derivable from the bundled WeaponPaints catalog. Default (paint 0) weapons are free and never sold. */
    public static function catalog(array $weapons, array $skins, array $gloves, array $rarity): array {
        $byId=[]; $meta=[];
        foreach ($weapons as $w) if (is_int($w['cs2_id'] ?? null) && $w['cs2_id']>0) $byId[$w['cs2_id']]=$w;
        foreach ($rarity['items'] ?? [] as $r) $meta[(int)$r['weapon_defindex'].':'.(int)$r['paint_id']]=$r;
        $out=[];
        $add=function(string $kind,int $def,int $paint,string $weapon,string $name)use(&$out,$meta){
            $m=$meta["$def:$paint"] ?? null;
            $out[]=['kind'=>$kind,'weapon_defindex'=>$def,'paint_id'=>$paint,'name'=>self::name($name),'rarity'=>$m['rarity'] ?? null,'phase'=>$m['phase'] ?? null,'finish'=>isset($m['finish']) ? self::name($m['finish'],64) : null]+self::propose($kind,$weapon,$def,$paint,$m);
        };
        foreach ($skins as $s) {
            $def=(int)$s['weapon_defindex']; $paint=(int)$s['paint']; $w=$byId[$def] ?? null;
            if ($w===null) continue;
            $kind=$w['category']==='Knife' ? 'knife' : 'weapon_skin';
            if ($kind==='weapon_skin' && $paint===0) continue;
            $add($kind,$def,$paint,$w['name'],$s['paint_name']);
        }
        foreach ($gloves as $g) {
            $def=(int)$g['weapon_defindex']; $paint=(int)$g['paint'];
            if ($def===0 || $paint===0) continue;
            $add('glove',$def,$paint,'',$g['paint_name']);
        }
        return $out;
    }

    private static function name(string $name, int $max=128): string {
        $name=trim(preg_replace('/\s+/u',' ',$name) ?? '');
        if ($name==='' || preg_match('//u',$name)!==1 || preg_match_all('/./us',$name)>$max) throw new InvalidArgumentException('Invalid catalog name');
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
