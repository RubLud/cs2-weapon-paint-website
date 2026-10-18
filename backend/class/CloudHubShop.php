<?php
require_once __DIR__.'/CloudHubAccounts.php';
require_once __DIR__.'/CloudHubAdmin.php';
require_once __DIR__.'/CloudHubShopPricing.php';
class CloudHubShopError extends RuntimeException {
    public function __construct(string $message, public int $status=409) { parent::__construct($message); }
}
/**
 * Cloud Coins shop. Buying grants permanent ownership of a base skin, knife or gloves;
 * personalization in WeaponPaints stays free. Ownership is not enforced here (Stage 6).
 * Lock order for purchases: wallet row (X), then product row (S). Price edits lock only the product.
 */
final class CloudHubShop {
    private const PAGE=48;
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    public static function enabled(): bool { return defined('CLOUDHUB_SHOP_ENABLED') && CLOUDHUB_SHOP_ENABLED===true; }
    public static function purchasesEnabled(): bool { return self::enabled() && defined('CLOUDHUB_SHOP_PURCHASES_ENABLED') && CLOUDHUB_SHOP_PURCHASES_ENABLED===true; }
    /** Flag plus applied migration 004. */
    public static function active(PDO $db): bool {
        if (!self::enabled()) return false;
        try { $ready=(bool)$db->query("SELECT 1 FROM cloudhub_schema_migrations WHERE version='004_cloudhub_shop.sql'")->fetchColumn(); }
        catch (PDOException) { $ready=false; }
        if (!$ready) error_log('CloudHub shop is enabled but migration 004_cloudhub_shop.sql is not applied');
        return $ready;
    }
    private static function uuid(mixed $id): string {
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$id)) throw new InvalidArgumentException('UUID v4 request ID required');
        return $id;
    }
    private static function productId(mixed $id): int {
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,9}$/D',$id) || (int)$id>4294967295) throw new InvalidArgumentException('Invalid product');
        return (int)$id;
    }
    private static function price(mixed $value): int {
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,6}$/D',$value) || (int)$value>1000000) throw new InvalidArgumentException('Price must be an integer between 1 and 1000000');
        return (int)$value;
    }
    private static function reason(mixed $value): string {
        $reason=is_string($value)?trim($value):'';
        if ($reason==='' || strlen($reason)>500 || preg_match('//u',$reason)!==1 || preg_match('/[\x00-\x1f\x7f]/',$reason)) throw new InvalidArgumentException('Reason required (max 500 bytes)');
        return $reason;
    }
    private static function fields(array $input,array $allowed): void {
        if (array_diff(array_keys($input),$allowed)) throw new InvalidArgumentException('Unsupported field');
    }
    private static function product(array $row): array {
        $out=['id'=>(int)$row['id'],'kind'=>$row['kind'],'weapon_defindex'=>(int)$row['weapon_defindex'],'paint_id'=>(int)$row['paint_id'],'name'=>$row['name'],'price_cc'=>(int)$row['price_cc']];
        if (array_key_exists('owned',$row)) $out['owned']=(bool)$row['owned'];
        if (array_key_exists('is_active',$row)) $out['is_active']=(bool)$row['is_active'];
        return $out;
    }
    /** Shared filter parsing for the public and OWNER listings. */
    private static function filters(array $q,bool $owner): array {
        foreach ($q as $v) if (!is_string($v)) throw new InvalidArgumentException('Invalid parameter');
        $where=[]; $args=[];
        $kind=$q['kind']??'all';
        if (!in_array($kind,['all','weapon_skin','knife','glove'],true)) throw new InvalidArgumentException('Invalid kind');
        if ($kind!=='all') { $where[]='p.kind=?'; $args[]=$kind; }
        if (($q['weapon']??'')!=='') {
            if (!preg_match('/^[1-9][0-9]{0,4}$/D',$q['weapon'])) throw new InvalidArgumentException('Invalid weapon');
            $where[]='p.weapon_defindex=?'; $args[]=(int)$q['weapon'];
        }
        $search=trim($q['search']??'');
        if ($search!=='') {
            if (strlen($search)>64 || preg_match('//u',$search)!==1) throw new InvalidArgumentException('Invalid search');
            $where[]="p.name LIKE ? ESCAPE '!'"; $args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';
        }
        if ($owner && ($q['active']??'')!=='') {
            if (!in_array($q['active'],['0','1'],true)) throw new InvalidArgumentException('Invalid active filter');
            $where[]='p.is_active=?'; $args[]=(int)$q['active'];
        }
        $order=match($q['sort']??'price_asc') {
            'price_asc'=>'p.price_cc ASC,p.id ASC','price_desc'=>'p.price_cc DESC,p.id ASC','name'=>'p.name ASC,p.id ASC',
            default=>throw new InvalidArgumentException('Invalid sort'),
        };
        $page=filter_var($q['page']??'1',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
        if ($page===false) throw new InvalidArgumentException('Invalid page');
        return [$where,$args,$order,$page];
    }
    public function catalog(?string $viewer,array $q): array {
        if ($viewer!==null) CloudHubAccounts::steamId($viewer);
        [$where,$args,$order,$page]=self::filters($q,false);
        $where[]='p.is_active=1';
        $sql=' FROM cloudhub_shop_products p WHERE '.implode(' AND ',$where);
        $total=(int)$this->q('SELECT COUNT(*)'.$sql,$args)->fetchColumn();
        $rows=$this->q('SELECT p.id,p.kind,p.weapon_defindex,p.paint_id,p.name,p.price_cc,EXISTS(SELECT 1 FROM cloudhub_player_inventory i WHERE i.steamid=? AND i.product_id=p.id) owned'.$sql.' ORDER BY '.$order.' LIMIT '.self::PAGE.' OFFSET '.(($page-1)*self::PAGE),array_merge([$viewer??''],$args))->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map([self::class,'product'],$rows),'total'=>$total,'page'=>$page,'page_size'=>self::PAGE,'purchases_enabled'=>self::purchasesEnabled()];
    }
    /** Session-owned list; includes items whose product was later deactivated. */
    public function inventory(string $steamid): array {
        CloudHubAccounts::steamId($steamid);
        $rows=$this->q('SELECT p.id,p.kind,p.weapon_defindex,p.paint_id,p.name,p.price_cc,i.source,i.acquired_at FROM cloudhub_player_inventory i JOIN cloudhub_shop_products p ON p.id=i.product_id WHERE i.steamid=? ORDER BY i.acquired_at DESC,p.id DESC LIMIT 5000',[$steamid])->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map(fn($r)=>self::product($r)+['source'=>$r['source'],'acquired_at'=>$r['acquired_at']],$rows)];
    }
    public function purchases(string $steamid,?string $before): array {
        CloudHubAccounts::steamId($steamid);
        if ($before!==null) CloudHubCommunity::positiveId($before);
        $rows=$this->q('SELECT u.id,u.price_cc,u.created_at,u.wallet_transaction_id,p.id product_id,p.name,p.kind,p.weapon_defindex,p.paint_id FROM cloudhub_shop_purchases u JOIN cloudhub_shop_products p ON p.id=u.product_id WHERE u.steamid=?'.($before!==null?' AND u.id<?':'').' ORDER BY u.id DESC LIMIT 50',$before!==null?[$steamid,$before]:[$steamid])->fetchAll(PDO::FETCH_ASSOC);
        $items=array_map(fn($r)=>['id'=>(string)$r['id'],'price_cc'=>(int)$r['price_cc'],'created_at'=>$r['created_at'],'transaction_id'=>(string)$r['wallet_transaction_id'],'product'=>['id'=>(int)$r['product_id'],'name'=>$r['name'],'kind'=>$r['kind'],'weapon_defindex'=>(int)$r['weapon_defindex'],'paint_id'=>(int)$r['paint_id']]],$rows);
        return ['items'=>$items,'next_before'=>count($items)===50?end($items)['id']:null];
    }
    /** Price and balance come only from the database; expected_price only protects the buyer from a changed price. */
    public function buy(string $steamid,array $input): array {
        CloudHubAccounts::steamId($steamid);
        if (!self::purchasesEnabled()) throw new CloudHubShopError('Zakupy są wyłączone.',403);
        self::fields($input,['product_id','request_id','expected_price']);
        $productId=self::productId($input['product_id']??null); $request=self::uuid($input['request_id']??null);
        $expected=isset($input['expected_price']) ? self::price($input['expected_price']) : null;
        $this->db->beginTransaction();
        try {
            $before=$this->q('SELECT balance FROM cloudhub_wallets WHERE steamid=? FOR UPDATE',[$steamid])->fetchColumn();
            if ($before===false) throw new CloudHubShopError('Zaloguj się ponownie przez Steam, aby utworzyć konto CloudHub.',409);
            // Locking reads after the wallet lock: a concurrent replay sees the committed first attempt.
            $old=$this->q('SELECT id,product_id,price_cc FROM cloudhub_shop_purchases WHERE steamid=? AND request_id=? FOR UPDATE',[$steamid,$request])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ((int)$old['product_id']!==$productId) throw new CloudHubShopError('Identyfikator żądania użyty dla innego zakupu.',409);
                $this->db->commit();
                return ['status'=>'duplicate','purchase_id'=>(string)$old['id'],'balance'=>(string)$before,'price_cc'=>(int)$old['price_cc']];
            }
            $product=$this->q('SELECT id,kind,weapon_defindex,paint_id,name,price_cc,is_active FROM cloudhub_shop_products WHERE id=? LOCK IN SHARE MODE',[$productId])->fetch(PDO::FETCH_ASSOC);
            if (!$product || !(int)$product['is_active']) throw new CloudHubShopError('Produkt jest niedostępny.',404);
            $price=(int)$product['price_cc'];
            if ($expected!==null && $expected!==$price) throw new CloudHubShopError('Cena produktu zmieniła się. Odśwież sklep.',409);
            if ($this->q('SELECT 1 FROM cloudhub_player_inventory WHERE steamid=? AND product_id=? FOR UPDATE',[$steamid,$productId])->fetchColumn()) throw new CloudHubShopError('Masz już ten przedmiot.',409);
            $before=(int)$before;
            if ($before<$price) throw new CloudHubShopError('Za mało Cloud Coins.',409);
            $after=$before-$price;
            $this->q('UPDATE cloudhub_wallets SET balance=? WHERE steamid=?',[$after,$steamid]);
            $this->q("INSERT INTO cloudhub_wallet_transactions(operation_key,steamid,amount,balance_before,balance_after,source,reason,created_at) VALUES (?,?,?,?,?,'shop',?,UTC_TIMESTAMP(6))",['shop:'.$steamid.':'.$request,$steamid,-$price,$before,$after,'Zakup: '.$product['name']]);
            $tx=(string)$this->db->lastInsertId();
            $this->q('INSERT INTO cloudhub_shop_purchases(steamid,product_id,price_cc,request_id,wallet_transaction_id,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))',[$steamid,$productId,$price,$request,$tx]);
            $purchase=(string)$this->db->lastInsertId();
            $this->q("INSERT INTO cloudhub_player_inventory(steamid,product_id,source,purchase_id,acquired_at) VALUES (?,?,'purchase',?,UTC_TIMESTAMP(6))",[$steamid,$productId,$purchase]);
            $this->db->commit();
            return ['status'=>'applied','purchase_id'=>$purchase,'transaction_id'=>$tx,'balance'=>(string)$after,'price_cc'=>$price,'product'=>self::product($product+['owned'=>1])];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    private function owner(string $actor,bool $lock=false): void { (new CloudHubAdmin($this->db))->requireOwner($actor,$lock); }
    public function adminProducts(string $actor,array $q): array {
        $this->owner($actor);
        [$where,$args,$order,$page]=self::filters($q,true);
        $sql=' FROM cloudhub_shop_products p'.($where?' WHERE '.implode(' AND ',$where):'');
        $total=(int)$this->q('SELECT COUNT(*)'.$sql,$args)->fetchColumn();
        $rows=$this->q('SELECT p.id,p.kind,p.weapon_defindex,p.paint_id,p.name,p.price_cc,p.is_active,(SELECT COUNT(*) FROM cloudhub_player_inventory i WHERE i.product_id=p.id) owners'.$sql.' ORDER BY '.$order.' LIMIT '.self::PAGE.' OFFSET '.(($page-1)*self::PAGE),$args)->fetchAll(PDO::FETCH_ASSOC);
        return ['items'=>array_map(fn($r)=>self::product($r)+['owners'=>(int)$r['owners']],$rows),'total'=>$total,'page'=>$page,'page_size'=>self::PAGE];
    }
    /** OWNER price/activity change. Idempotent per (OWNER, request_id); price history keeps every change. */
    public function setProduct(string $actor,array $input): array {
        $this->owner($actor);
        self::fields($input,['product_id','price_cc','is_active','reason','request_id']);
        $id=self::productId($input['product_id']??null); $price=self::price($input['price_cc']??null);
        if (!in_array($input['is_active']??null,['0','1'],true)) throw new InvalidArgumentException('is_active must be 0 or 1');
        $active=(int)$input['is_active']; $reason=self::reason($input['reason']??null); $request=self::uuid($input['request_id']??null);
        $this->db->beginTransaction();
        try {
            $this->owner($actor,true);
            $row=$this->q('SELECT id,kind,weapon_defindex,paint_id,name,price_cc,is_active FROM cloudhub_shop_products WHERE id=? FOR UPDATE',[$id])->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new CloudHubShopError('Produkt nie istnieje.',404);
            $metadata=json_encode(['request_id'=>$request,'product_id'=>$id,'old_price_cc'=>(int)$row['price_cc'],'new_price_cc'=>$price,'old_active'=>(int)$row['is_active'],'new_active'=>$active],JSON_THROW_ON_ERROR);
            $old=$this->q('SELECT product_id,new_price_cc,new_active,reason FROM cloudhub_shop_price_history WHERE actor_steamid=? AND request_id=? FOR UPDATE',[$actor,$request])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ((int)$old['product_id']!==$id || (int)$old['new_price_cc']!==$price || (int)$old['new_active']!==$active || $old['reason']!==$reason) throw new CloudHubShopError('Request ID reused with different content',409);
                $this->db->commit();
                return ['status'=>'duplicate','product'=>self::product($row)];
            }
            $this->q('UPDATE cloudhub_shop_products SET price_cc=?,is_active=?,updated_at=UTC_TIMESTAMP(6) WHERE id=?',[$price,$active,$id]);
            $this->q('INSERT INTO cloudhub_shop_price_history(product_id,old_price_cc,new_price_cc,old_active,new_active,actor_steamid,request_id,reason,created_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',[$id,$row['price_cc'],$price,$row['is_active'],$active,$actor,$request,$reason]);
            $this->q("INSERT INTO cloudhub_admin_audit_logs(actor_steamid,action,reason,metadata_json,created_at) VALUES (?,'shop.product',?,?,UTC_TIMESTAMP(6))",[$actor,$reason,$metadata]);
            $this->db->commit();
            return ['status'=>'applied','product'=>self::product(['price_cc'=>$price,'is_active'=>$active]+$row)];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function priceHistory(string $actor,string $product): array {
        $this->owner($actor);
        $id=self::productId($product);
        $rows=$this->q('SELECT id,old_price_cc,new_price_cc,old_active,new_active,actor_steamid,reason,created_at FROM cloudhub_shop_price_history WHERE product_id=? ORDER BY id DESC LIMIT 100',[$id])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) { $r['id']=(string)$r['id']; foreach (['old_price_cc','new_price_cc','old_active','new_active'] as $k) $r[$k]=$r[$k]===null?null:(int)$r[$k]; }
        return ['entries'=>$rows];
    }
    /** Economy overview for §11. Averages per player use the last 7 days of round rewards. */
    public function stats(string $actor): array {
        $this->owner($actor);
        $one=fn(string $sql)=>(string)($this->db->query($sql)->fetchColumn() ?: '0');
        $sources=$this->db->query("SELECT source,SUM(CASE WHEN amount>0 THEN amount ELSE 0 END) earned,SUM(CASE WHEN amount<0 THEN -amount ELSE 0 END) spent FROM cloudhub_wallet_transactions GROUP BY source ORDER BY source")->fetchAll(PDO::FETCH_ASSOC);
        $week=$this->db->query("SELECT COALESCE(SUM(amount),0) cc,COUNT(DISTINCT steamid) players FROM cloudhub_wallet_transactions WHERE source='round' AND amount>0 AND created_at>=UTC_TIMESTAMP(6)-INTERVAL 7 DAY")->fetch(PDO::FETCH_ASSOC);
        return [
            'circulation_cc'=>$one('SELECT SUM(balance) FROM cloudhub_wallets'),
            'purchases'=>$one('SELECT COUNT(*) FROM cloudhub_shop_purchases'),
            'spent_cc'=>$one('SELECT SUM(price_cc) FROM cloudhub_shop_purchases'),
            'products'=>$one('SELECT COUNT(*) FROM cloudhub_shop_products'),
            'active_products'=>$one('SELECT COUNT(*) FROM cloudhub_shop_products WHERE is_active=1'),
            'sources'=>array_map(fn($r)=>['source'=>$r['source'],'earned'=>(string)$r['earned'],'spent'=>(string)$r['spent']],$sources),
            'round_cc_7d'=>(string)$week['cc'],'earning_players_7d'=>(int)$week['players'],
            'purchases_enabled'=>self::purchasesEnabled(),
        ];
    }
    /** CLI seeding: inserts missing products only, never changes OWNER prices. */
    public function seed(array $products,bool $activate,bool $apply): array {
        if (PHP_SAPI!=='cli') throw new LogicException('CLI only');
        $existing=[];
        foreach ($this->db->query('SELECT weapon_defindex,paint_id FROM cloudhub_shop_products')->fetchAll(PDO::FETCH_NUM) as [$d,$p]) $existing["$d:$p"]=true;
        $new=array_values(array_filter($products,fn($p)=>!isset($existing[$p['weapon_defindex'].':'.$p['paint_id']])));
        if ($apply && $new) {
            $this->db->beginTransaction();
            try {
                $ins=$this->db->prepare('INSERT INTO cloudhub_shop_products(kind,weapon_defindex,paint_id,name,price_cc,is_active,created_at,updated_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))');
                $hist=$this->db->prepare("INSERT INTO cloudhub_shop_price_history(product_id,new_price_cc,new_active,reason,created_at) VALUES (?,?,?,'Catalog proposal (CLI)',UTC_TIMESTAMP(6))");
                foreach ($new as $p) {
                    $ins->execute([$p['kind'],$p['weapon_defindex'],$p['paint_id'],$p['name'],$p['price_cc'],(int)$activate]);
                    $hist->execute([$this->db->lastInsertId(),$p['price_cc'],(int)$activate]);
                }
                $this->db->commit();
            } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        }
        return ['catalog'=>count($products),'existing'=>count($products)-count($new),'new'=>count($new),'applied'=>$apply];
    }
}
