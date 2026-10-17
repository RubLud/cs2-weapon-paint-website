<?php
require_once __DIR__.'/CloudHubAccounts.php';
require_once __DIR__.'/CloudHubCommunity.php';
final class CloudHubForbidden extends RuntimeException {}
final class CloudHubConflict extends RuntimeException {}
final class CloudHubAdmin {
    public function __construct(private PDO $db) {}
    private function query(string $sql,array $args=[]): PDOStatement {
        $s=$this->db->prepare($sql); $s->execute($args); return $s;
    }
    public function requireOwner(string $actor, bool $lock=false): void {
        CloudHubAccounts::steamId($actor);
        // Lock the actual grant during mutations: concurrent role removal cannot pass between check and commit.
        if (!$this->query("SELECT role FROM cloudhub_player_roles WHERE steamid=? AND role='OWNER'".($lock?' FOR UPDATE':''),[$actor])->fetchColumn()) throw new CloudHubForbidden('OWNER required');
    }
    public function players(string $actor,string $search,int $page): array {
        $this->requireOwner($actor);
        return (new CloudHubCommunity($this->db))->leaderboard(null,$search,'rp',50,$page,true);
    }
    public function audit(string $actor,?string $before): array {
        $this->requireOwner($actor);
        if ($before!==null) CloudHubCommunity::positiveId($before);
        $rows=$this->query('SELECT id,actor_steamid,target_steamid,action,reason,metadata_json,created_at FROM cloudhub_admin_audit_logs'.($before!==null?' WHERE id<?':'').' ORDER BY id DESC LIMIT 50',$before!==null?[$before]:[])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['id']=(string)$row['id'];
        return ['entries'=>$rows,'next_before'=>count($rows)===50?end($rows)['id']:null];
    }
    public function config(string $actor): array {
        $this->requireOwner($actor);
        $ranks=$this->db->query('SELECT * FROM cloudhub_rank_config ORDER BY min_rp')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ranks as &$r) $r['min_rp']=(string)$r['min_rp'];
        return ['ranks'=>$ranks,'rewards'=>$this->db->query('SELECT rule_key,value FROM cloudhub_reward_rules ORDER BY rule_key')->fetchAll(PDO::FETCH_ASSOC),'editable'=>false,'adjustments_enabled'=>defined('CLOUDHUB_ADMIN_CC_ENABLED') && CLOUDHUB_ADMIN_CC_ENABLED===true];
    }
    public function adjust(string $actor,array $input): array {
        $this->requireOwner($actor);
        if (!defined('CLOUDHUB_ADMIN_CC_ENABLED') || CLOUDHUB_ADMIN_CC_ENABLED!==true) throw new CloudHubForbidden('CC adjustments disabled');
        if (array_diff(array_keys($input),['steamid','amount','reason','request_id'])) throw new InvalidArgumentException('Unsupported field');
        foreach (['steamid','amount','reason','request_id'] as $key) if (!is_string($input[$key]??null)) throw new InvalidArgumentException('Missing field');
        $id=CloudHubAccounts::steamId($input['steamid']);
        if (!preg_match('/^-?[1-9][0-9]{0,6}$/D',$input['amount']) || abs((int)$input['amount'])>1000000) throw new InvalidArgumentException('Amount must be a nonzero integer between -1000000 and 1000000');
        $amount=(int)$input['amount']; $reason=trim($input['reason']);
        if ($reason==='' || strlen($reason)>500 || preg_match('//u',$reason)!==1 || preg_match('/[\x00-\x1f\x7f]/',$reason)) throw new InvalidArgumentException('Reason required (max 500 bytes)');
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$input['request_id'])) throw new InvalidArgumentException('UUID v4 request ID required');
        $key='admin:'.$actor.':'.$input['request_id'];
        $this->db->beginTransaction();
        try {
            $this->requireOwner($actor,true);
            $old=$this->query('SELECT id,steamid,amount,reason,balance_after FROM cloudhub_wallet_transactions WHERE operation_key=? FOR UPDATE',[$key])->fetch(PDO::FETCH_ASSOC);
            if ($old) {
                if ($old['steamid']!==$id || (string)$old['amount']!==(string)$amount || $old['reason']!==$reason) throw new CloudHubConflict('Request ID reused with different content');
                $this->db->commit();
                return ['status'=>'duplicate','transaction_id'=>(string)$old['id'],'balance'=>(string)$old['balance_after']];
            }
            $before=$this->query('SELECT balance FROM cloudhub_wallets WHERE steamid=? FOR UPDATE',[$id])->fetchColumn();
            if ($before===false) throw new InvalidArgumentException('Player not found');
            $before=(int)$before;
            if ($amount<0 && $before < -$amount) throw new CloudHubConflict('Insufficient Cloud Coins');
            if ($amount>0 && $before>PHP_INT_MAX-$amount) throw new CloudHubConflict('Balance limit exceeded');
            $after=$before+$amount;
            $this->query('UPDATE cloudhub_wallets SET balance=? WHERE steamid=?',[$after,$id]);
            $this->query("INSERT INTO cloudhub_wallet_transactions(operation_key,steamid,amount,balance_before,balance_after,source,reason,actor_steamid) VALUES (?,?,?,?,?,'admin',?,?)",[$key,$id,$amount,$before,$after,$reason,$actor]);
            $tx=(string)$this->db->lastInsertId();
            $metadata=json_encode(['transaction_id'=>$tx,'request_id'=>$input['request_id'],'amount'=>(string)$amount,'balance_before'=>(string)$before,'balance_after'=>(string)$after],JSON_THROW_ON_ERROR);
            $this->query("INSERT INTO cloudhub_admin_audit_logs(actor_steamid,target_steamid,action,reason,metadata_json) VALUES (?,?,'wallet.adjust',?,?)",[$actor,$id,$reason,$metadata]);
            $this->db->commit();
            return ['status'=>'applied','transaction_id'=>$tx,'balance'=>(string)$after];
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
