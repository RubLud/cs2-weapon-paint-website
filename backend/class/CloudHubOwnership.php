<?php
require_once __DIR__.'/Security.php';
require_once __DIR__.'/CloudHubShop.php';
class CloudHubOwnershipError extends RuntimeException {
    /** @param list<array{weapon_defindex:int,paint_id:int}> $items */
    public function __construct(public array $items) { parent::__construct('Nie posiadasz tego przedmiotu. Kup go w sklepie CloudHub.'); }
}
/**
 * Stage 6: WeaponPaints ownership enforcement for base skins, knives and gloves.
 * Off unless CLOUDHUB_OWNERSHIP_ENFORCED, the shop and migration 005 are active and an OWNER snapshot
 * of existing WeaponPaints data exists, so nobody loses what they already had.
 * Personalization (wear, seed, StatTrak, nametag, stickers, charms) is never checked; agents, music and pins stay free.
 * Ownership is only ever added, so a check followed by a write in one request cannot race with a revocation.
 */
final class CloudHubOwnership {
    private const DEFAULT_KNIVES=['weapon_knife','weapon_knife_t'];
    public function __construct(private PDO $db) {}
    public static function enabled(): bool { return defined('CLOUDHUB_OWNERSHIP_ENFORCED') && CLOUDHUB_OWNERSHIP_ENFORCED===true; }
    public static function installed(PDO $db): bool {
        try { return (bool)$db->query("SELECT 1 FROM cloudhub_schema_migrations WHERE version='005_cloudhub_ownership.sql'")->fetchColumn(); }
        catch (PDOException) { return false; }
    }
    public static function active(PDO $db): bool {
        if (!self::enabled() || !CloudHubShop::active($db)) return false;
        if (!self::installed($db)) { error_log('CloudHub ownership enforcement is enabled but migration 005_cloudhub_ownership.sql is not applied'); return false; }
        $ready=(bool)$db->query('SELECT 1 FROM cloudhub_ownership_snapshots LIMIT 1')->fetchColumn();
        if (!$ready) error_log('CloudHub ownership enforcement is enabled but no OWNER snapshot exists; enforcement stays off');
        return $ready;
    }
    /** Knife and glove defindexes: their paint 0 row is a model, not a free default finish. @return array<int,true> */
    private static function models(): array {
        static $ids=null;
        if ($ids===null) {
            $ids=[];
            foreach (wp_catalog('weapons.json') as $w) if (($w['category'] ?? '')==='Knife' && is_int($w['cs2_id'] ?? null)) $ids[$w['cs2_id']]=true;
            foreach (wp_catalog('data/gloves_en.json') as $g) if ((int)$g['weapon_defindex']>0) $ids[(int)$g['weapon_defindex']]=true;
        }
        return $ids;
    }
    /** Knife model name as stored in wp_player_knife: null for the free default knife, -1 when unknown. */
    public static function knifeDefindex(string $name): ?int {
        if ($name==='' || in_array($name,self::DEFAULT_KNIVES,true)) return null;
        static $byName=null;
        if ($byName===null) { $byName=[]; foreach (wp_catalog('weapons.json') as $w) if (($w['category'] ?? '')==='Knife' && is_int($w['cs2_id'] ?? null)) $byName['weapon_'.$w['name']]=$w['cs2_id']; }
        return $byName[$name] ?? -1;
    }
    /** Items a WeaponPaints team snapshot (loadout payload or wp_* rows) equips. */
    public static function teamItems(array $team): array {
        $items=[];
        foreach (is_array($team['skins'] ?? null) ? $team['skins'] : [] as $row) if (is_array($row) && isset($row['weapon_defindex'])) $items[]=[(int)$row['weapon_defindex'],(int)($row['weapon_paint_id'] ?? 0)];
        if (!empty($team['knife']) && is_string($team['knife'])) { $def=self::knifeDefindex($team['knife']); if ($def!==null) $items[]=[$def,0]; }
        $g=$team['gloves'] ?? null;
        if (is_array($g) && isset($g['weapon_defindex'])) $items[]=[(int)$g['weapon_defindex'],(int)($g['weapon_paint_id'] ?? 0)];
        return $items;
    }
    private function owns(string $steamid,int $def,int $paint): bool {
        $model=$paint===0 && isset(self::models()[$def]);
        // A knife or glove model is usable when any finish of it is owned.
        $legacy=$model ? 'SELECT 1 FROM cloudhub_legacy_ownership WHERE steamid=? AND weapon_defindex=?' : 'SELECT 1 FROM cloudhub_legacy_ownership WHERE steamid=? AND weapon_defindex=? AND paint_id=?';
        $s=$this->db->prepare($legacy.' LIMIT 1'); $s->execute($model ? [$steamid,$def] : [$steamid,$def,$paint]);
        if ($s->fetchColumn()) return true;
        $bought=$model ? "SELECT 1 FROM cloudhub_player_inventory i JOIN cloudhub_shop_products p ON p.id=i.product_id WHERE i.steamid=? AND p.kind IN ('knife','glove') AND p.weapon_defindex=?" : 'SELECT 1 FROM cloudhub_player_inventory i JOIN cloudhub_shop_products p ON p.id=i.product_id WHERE i.steamid=? AND p.weapon_defindex=? AND p.paint_id=?';
        $s=$this->db->prepare($bought.' LIMIT 1'); $s->execute($model ? [$steamid,$def] : [$steamid,$def,$paint]);
        return (bool)$s->fetchColumn();
    }
    /**
     * Items the player may not equip. Free: default gloves (defindex 0) and default weapon finishes (paint 0).
     * Anything else needs a purchase, grant or legacy snapshot row. Unknown combinations are refused, not assumed free.
     * @param list<array{0:int,1:int}> $items [weapon_defindex, paint_id]; knife model as [defindex, 0], -1 for an unknown knife.
     */
    public function missing(string $steamid,array $items): array {
        $out=[];
        foreach ($items as [$def,$paint]) {
            $key="$def:$paint";
            if (isset($out[$key]) || $def===0) continue;
            if ($paint===0 && $def>0 && !isset(self::models()[$def])) continue;
            if ($def<0 || !$this->owns($steamid,$def,$paint)) $out[$key]=['weapon_defindex'=>$def,'paint_id'=>$paint];
        }
        return array_values($out);
    }
    /** Throws when enforcement is active and any item is not owned; a no-op otherwise. */
    public function require(string $steamid,array $items): void {
        if (!self::active($this->db)) return;
        $missing=$this->missing($steamid,$items);
        if ($missing) throw new CloudHubOwnershipError($missing);
    }

    /** Everything WeaponPaints currently stores per player: equipped rows, knife models, glove models and saved loadouts. */
    private function collect(): array {
        $out=[]; $valid=fn($id)=>is_string($id) && preg_match('/^[0-9]{17}$/D',$id)===1;
        $add=function(string $steamid,int $def,int $paint,string $origin)use(&$out){ if ($def>0 && $paint>=0 && !isset($out[$steamid]["$def:$paint"])) $out[$steamid]["$def:$paint"]=[$def,$paint,$origin]; };
        $models=self::models();
        foreach ($this->db->query('SELECT steamid,weapon_defindex,weapon_paint_id FROM wp_player_skins')->fetchAll(PDO::FETCH_NUM) as [$id,$def,$paint])
            if ($valid($id) && ((int)$paint>0 || isset($models[(int)$def]))) $add($id,(int)$def,(int)$paint,'equipped');
        foreach ($this->db->query('SELECT steamid,knife FROM wp_player_knife')->fetchAll(PDO::FETCH_NUM) as [$id,$knife]) {
            $def=self::knifeDefindex((string)$knife);
            if ($valid($id) && $def!==null && $def>0) $add($id,$def,0,'knife');
        }
        foreach ($this->db->query('SELECT g.steamid,g.weapon_defindex,COALESCE(s.weapon_paint_id,0) FROM wp_player_gloves g LEFT JOIN wp_player_skins s ON s.steamid=g.steamid AND s.weapon_team=g.weapon_team AND s.weapon_defindex=g.weapon_defindex')->fetchAll(PDO::FETCH_NUM) as [$id,$def,$paint])
            if ($valid($id)) $add($id,(int)$def,(int)$paint,'equipped');
        try { $loadouts=$this->db->query('SELECT steamid,payload FROM wp_saved_loadouts')->fetchAll(PDO::FETCH_NUM); }
        catch (PDOException) { $loadouts=[]; }
        foreach ($loadouts as [$id,$payload]) {
            $p=json_decode((string)$payload,true);
            if (!$valid($id) || !is_array($p)) continue;
            foreach ([$p['t'] ?? null,$p['ct'] ?? null,$p['team'] ?? null,isset($p['skins']) ? $p : null] as $team)
                if (is_array($team)) foreach (self::teamItems($team) as [$def,$paint]) if ($paint>0 || isset($models[$def])) $add($id,$def,$paint,'loadout');
        }
        return $out;
    }
    /** CLI only. Dry-run by default. Additive: rows already present are kept, nothing is deleted. */
    public function snapshot(bool $apply,string $reason=''): array {
        if (PHP_SAPI!=='cli') throw new LogicException('CLI only');
        if (!self::installed($this->db)) throw new RuntimeException('Migration 005_cloudhub_ownership.sql is not applied');
        $items=$this->collect();
        $count=array_sum(array_map('count',$items));
        $origins=[]; foreach ($items as $rows) foreach ($rows as [, , $o]) $origins[$o]=($origins[$o] ?? 0)+1;
        $result=['players'=>count($items),'items'=>$count,'origins'=>$origins,'added'=>0,'applied'=>$apply];
        if (!$apply) {
            $s=$this->db->query('SELECT COUNT(*) FROM cloudhub_legacy_ownership'); $result['already_recorded']=(int)$s->fetchColumn();
            return $result;
        }
        $reason=trim($reason);
        if ($reason==='' || mb_strlen($reason)>500) throw new InvalidArgumentException('A reason (1-500 characters) is required');
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO cloudhub_ownership_snapshots(reason,players,items,added,created_at) VALUES (?,?,?,0,UTC_TIMESTAMP(6))')->execute([$reason,count($items),$count]);
            $snap=(int)$this->db->lastInsertId();
            $ins=$this->db->prepare('INSERT IGNORE INTO cloudhub_legacy_ownership(steamid,weapon_defindex,paint_id,origin,snapshot_id,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))');
            $added=0;
            foreach ($items as $steamid=>$rows) foreach ($rows as [$def,$paint,$origin]) { $ins->execute([(string)$steamid,$def,$paint,$origin,$snap]); $added+=$ins->rowCount(); }
            $this->db->prepare('UPDATE cloudhub_ownership_snapshots SET added=? WHERE id=?')->execute([$added,$snap]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        return ['snapshot_id'=>$snap,'added'=>$added]+$result;
    }
    /** Read-only: equipped WeaponPaints items that enforcement would refuse (e.g. picked in-game after the snapshot). */
    public function audit(int $limit=50): array {
        if (!self::installed($this->db)) throw new RuntimeException('Migration 005_cloudhub_ownership.sql is not applied');
        $players=0; $items=0; $sample=[];
        foreach ($this->collectEquipped() as $steamid=>$list) {
            $missing=$this->missing((string)$steamid,$list);
            if (!$missing) continue;
            $players++; $items+=count($missing);
            if (count($sample)<$limit) $sample[]=['steamid'=>(string)$steamid,'items'=>$missing];
        }
        return ['players'=>$players,'items'=>$items,'sample'=>$sample];
    }
    private function collectEquipped(): array {
        $out=[];
        foreach ($this->collect() as $steamid=>$rows) foreach ($rows as [$def,$paint,$origin]) if ($origin!=='loadout') $out[$steamid][]=[$def,$paint];
        return $out;
    }
}
/** WeaponPaints endpoints: refuse the write with 403 before anything is stored. Rolls back and drops buffered output. */
function wp_require_owned(PDO $db,string $steamid,array $items): void {
    try { (new CloudHubOwnership($db))->require($steamid,$items); }
    catch (CloudHubOwnershipError $e) {
        if ($db->inTransaction()) $db->rollBack();
        while (ob_get_level()>0) ob_end_clean();
        http_response_code(403);
        echo json_encode(['error'=>$e->getMessage(),'code'=>'not_owned','items'=>$e->items]);
        exit;
    }
}
