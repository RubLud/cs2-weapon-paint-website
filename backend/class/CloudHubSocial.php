<?php
require_once __DIR__.'/CloudHubAccounts.php';
require_once __DIR__.'/CloudHubCommunity.php';
class CloudHubSocialError extends RuntimeException {
    public function __construct(string $message, public int $status=403) { parent::__construct($message); }
}
/** Social writes share a database-scoped lock: privacy, blocks and mutations commit atomically. */
final class CloudHubSocial {
    public function __construct(private PDO $db) {}
    private function q(string $sql,array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    public static function enabled(): bool { return defined('CLOUDHUB_SOCIAL_ENABLED') && CLOUDHUB_SOCIAL_ENABLED===true; }
    /** Flag plus applied migration 003: an enabled flag without the schema degrades to Stage 3 behaviour. */
    public static function active(PDO $db): bool {
        if (!self::enabled()) return false;
        try { $ready=(bool)$db->query("SELECT 1 FROM cloudhub_schema_migrations WHERE version='003_cloudhub_social.sql'")->fetchColumn(); }
        catch (PDOException) { $ready=false; }
        if (!$ready) error_log('CloudHub social is enabled but migration 003_cloudhub_social.sql is not applied');
        return $ready;
    }
    public static function id(string $id): string {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id) || strlen($id)===19 && strcmp($id,'9223372036854775807')>0) throw new InvalidArgumentException('Nieprawidłowy identyfikator.');
        return $id;
    }
    public static function text(mixed $text,int $max): string {
        if (!is_string($text) || preg_match('//u',$text)!==1 || preg_match_all('/./us',$text)>$max || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$text) || trim($text)==='') throw new InvalidArgumentException("Wpisz tekst o długości od 1 do $max znaków.");
        return trim($text);
    }
    public function blocked(?string $a,string $b): bool {
        return $a!==null && (bool)$this->q('SELECT 1 FROM cloudhub_blocks WHERE (actor=? AND target=?) OR (actor=? AND target=?) LIMIT 1',[$a,$b,$b,$a])->fetchColumn();
    }
    public function friends(?string $a,string $b): bool {
        if ($a===null || $a===$b) return false;
        $pair=[$a,$b]; sort($pair,SORT_STRING);
        return (bool)$this->q("SELECT 1 FROM cloudhub_friendships WHERE low_id=? AND high_id=? AND status='accepted'",$pair)->fetchColumn();
    }
    public function allows(string $policy,string $owner,?string $viewer): bool {
        if ($owner===$viewer) return true;
        if ($this->blocked($viewer,$owner)) return false;
        return in_array($policy,['public','everyone'],true) || $policy==='friends' && $this->friends($viewer,$owner);
    }
    public function settings(string $id): array {
        CloudHubAccounts::steamId($id);
        $r=$this->q("SELECT f.*,COALESCE(s.friends_visibility,'private') friends_visibility,COALESCE(s.invites_policy,'everyone') invites_policy FROM cloudhub_profiles f LEFT JOIN cloudhub_social_settings s USING(steamid) WHERE f.steamid=?",[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new CloudHubSocialError('Profil niedostępny.',404);
        return $r;
    }
    private function visible(string $id,?string $viewer): array {
        $s=$this->settings($id);
        if (!$this->allows($s['profile_visibility'],$id,$viewer)) throw new CloudHubSocialError('Profil niedostępny.',404);
        return $s;
    }
    public function canModerate(string $actor): bool { return (new CloudHubAccounts($this->db))->can($actor,'moderation.manage'); }
    public function relation(string $actor,string $target): array {
        $settings=$this->settings($target);
        if ((bool)$this->q('SELECT 1 FROM cloudhub_blocks WHERE actor=? AND target=?',[$actor,$target])->fetchColumn()) return ['status'=>'blocked','blocked_by_me'=>true];
        // A block by the other party looks the same as an account that does not accept this viewer's invitations.
        if ($this->blocked($actor,$target)) return ['status'=>'unavailable','blocked_by_me'=>false];
        $pair=[$actor,$target]; sort($pair,SORT_STRING);
        $r=$this->q('SELECT sender,status FROM cloudhub_friendships WHERE low_id=? AND high_id=?',$pair)->fetch(PDO::FETCH_ASSOC);
        if (!$r) return ['status'=>$actor!==$target && $this->allows($settings['invites_policy'],$target,$actor)?'none':'unavailable','blocked_by_me'=>false];
        return ['status'=>$r['status']==='accepted'?'friends':($r['sender']===$actor?'sent':'received'),'blocked_by_me'=>false];
    }
    private function notify(string $recipient,string $actor,string $kind,string $profile,?string $comment=null): void {
        if ($recipient===$actor || $this->blocked($recipient,$actor)) return;
        $this->q('INSERT INTO cloudhub_notifications(recipient,actor,kind,profile_id,comment_id,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))',[$recipient,$actor,$kind,$profile,$comment]);
    }
    private function limit(string $actor,string $action,int $max,int $gap=0): void {
        $r=$this->q('SELECT attempts,TIMESTAMPDIFF(SECOND,window_start,UTC_TIMESTAMP(6)) age,TIMESTAMPDIFF(SECOND,last_at,UTC_TIMESTAMP(6)) gap FROM cloudhub_social_limits WHERE actor=? AND action=?',[$actor,$action])->fetch(PDO::FETCH_ASSOC);
        if ($r && ((int)$r['gap']<$gap || (int)$r['age']<3600 && (int)$r['attempts']>=$max)) throw new CloudHubSocialError('Zbyt wiele operacji. Spróbuj ponownie później.',429);
        $this->q('INSERT INTO cloudhub_social_limits(actor,action,window_start,last_at,attempts) VALUES (?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),1) ON DUPLICATE KEY UPDATE attempts=IF(window_start<UTC_TIMESTAMP(6)-INTERVAL 1 HOUR,1,attempts+1),window_start=IF(window_start<UTC_TIMESTAMP(6)-INTERVAL 1 HOUR,UTC_TIMESTAMP(6),window_start),last_at=UTC_TIMESTAMP(6)',[$actor,$action]);
    }
    /** All writes enter here. Actor is always taken from the Steam session by the endpoint. */
    public function mutate(string $actor,string $action,array $input): array {
        CloudHubAccounts::steamId($actor);
        $fields=['invite'=>['target'],'accept'=>['target'],'reject'=>['target'],'cancel'=>['target'],'remove'=>['target'],'block'=>['target'],'unblock'=>['target'],'comment'=>['target','body'],'delete'=>['id','reason'],'report'=>['id','reason'],'resolve'=>['id','reason'],'read'=>['id'],'read_all'=>[],'settings'=>['bio','profile_visibility','activity_visibility','friends_visibility','invites_policy','comments_policy','theme','banner']];
        if (!isset($fields[$action]) || array_diff(array_keys($input),$fields[$action])) throw new InvalidArgumentException('Nieobsługiwane pola operacji.');
        foreach ($input as $v) if (!is_string($v)) throw new InvalidArgumentException('Nieprawidłowa wartość.');
        $lock='cloudhub-social-'.substr(hash('sha256',(string)$this->db->query('SELECT DATABASE()')->fetchColumn()),0,32);
        if ((int)$this->q('SELECT GET_LOCK(?,5)',[$lock])->fetchColumn()!==1) throw new CloudHubSocialError('Serwis zajęty. Ponów operację.',503);
        try {
            $this->db->beginTransaction();
            $this->settings($actor);
            $this->limit($actor,'write',120);
            $result=$this->write($actor,$action,$input);
            $this->db->commit(); return $result;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
        finally { $this->q('SELECT RELEASE_LOCK(?)',[$lock]); }
    }
    private function write(string $actor,string $action,array $in): array {
        if ($action==='settings') {
            $old=$this->settings($actor); $v=array_merge($old,$in);
            $bio=$v['bio']; if ($bio!=='') self::text($bio,1000);
            foreach (['profile_visibility','activity_visibility','friends_visibility'] as $k) if (!in_array($v[$k],['public','friends','private'],true)) throw new InvalidArgumentException('Nieprawidłowa prywatność.');
            foreach (['invites_policy','comments_policy'] as $k) if (!in_array($v[$k],['everyone','friends','disabled'],true)) throw new InvalidArgumentException('Nieprawidłowa polityka.');
            if (!in_array($v['theme'],['cloudhub','violet','emerald','amber'],true) || !in_array($v['banner'],['default','aurora','grid','sunset'],true)) throw new InvalidArgumentException('Wybierz wbudowany motyw.');
            $this->q('UPDATE cloudhub_profiles SET bio=?,profile_visibility=?,activity_visibility=?,comments_policy=?,theme=?,banner=? WHERE steamid=?',[$bio,$v['profile_visibility'],$v['activity_visibility'],$v['comments_policy'],$v['theme'],$v['banner'],$actor]);
            $this->q('INSERT INTO cloudhub_social_settings(steamid,friends_visibility,invites_policy) VALUES (?,?,?) ON DUPLICATE KEY UPDATE friends_visibility=VALUES(friends_visibility),invites_policy=VALUES(invites_policy)',[$actor,$v['friends_visibility'],$v['invites_policy']]);
            return ['saved'=>true];
        }
        if ($action==='read') {
            $id=self::id($in['id']??'');
            $this->q('UPDATE cloudhub_notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP(6)) WHERE id=? AND recipient=?',[$id,$actor]);
            return ['saved'=>true];
        }
        if ($action==='read_all') {
            $this->q('UPDATE cloudhub_notifications SET read_at=UTC_TIMESTAMP(6) WHERE recipient=? AND read_at IS NULL',[$actor]);
            return ['saved'=>true];
        }
        if (in_array($action,['delete','report','resolve'],true)) return $this->commentAction($actor,$action,$in);
        $target=CloudHubAccounts::steamId($in['target']??''); $settings=$this->settings($target);
        $pair=[$actor,$target]; sort($pair,SORT_STRING);
        if ($action==='comment') {
            $this->visible($target,$actor);
            if (!$this->allows($settings['comments_policy'],$target,$actor)) throw new CloudHubSocialError('Ten profil nie przyjmuje Twoich komentarzy.');
            $body=self::text($in['body']??null,1000); $this->limit($actor,'comment',30,15);
            $this->q('INSERT INTO cloudhub_profile_comments(profile_id,author,body,created_at) VALUES (?,?,?,UTC_TIMESTAMP(6))',[$target,$actor,$body]); $id=(string)$this->db->lastInsertId();
            $this->notify($target,$actor,'comment',$target,$id); return ['id'=>$id];
        }
        if ($actor===$target) throw new InvalidArgumentException('Nie możesz utworzyć relacji z własnym kontem.');
        if ($action==='block') {
            $this->q('INSERT IGNORE INTO cloudhub_blocks(actor,target,created_at) VALUES (?,?,UTC_TIMESTAMP(6))',[$actor,$target]);
            $this->q('DELETE FROM cloudhub_friendships WHERE low_id=? AND high_id=?',$pair);
            $this->q('DELETE FROM cloudhub_notifications WHERE (recipient=? AND actor=?) OR (recipient=? AND actor=?)',[$actor,$target,$target,$actor]);
        } elseif ($action==='unblock') $this->q('DELETE FROM cloudhub_blocks WHERE actor=? AND target=?',[$actor,$target]);
        else {
            if ($this->blocked($actor,$target)) throw new CloudHubSocialError('Interakcja jest niedostępna.');
            $r=$this->q('SELECT * FROM cloudhub_friendships WHERE low_id=? AND high_id=?',$pair)->fetch(PDO::FETCH_ASSOC);
            if ($action==='invite') {
                if ($r) throw new CloudHubSocialError('Relacja już istnieje.',409);
                if (!$this->allows($settings['invites_policy'],$target,$actor)) throw new CloudHubSocialError('Gracz nie przyjmuje Twoich zaproszeń.');
                $this->limit($actor,'invite',20,3);
                $this->q('INSERT INTO cloudhub_friendships(low_id,high_id,sender,created_at) VALUES (?,?,?,UTC_TIMESTAMP(6))',[...$pair,$actor]);
                $this->notify($target,$actor,'invite',$actor);
            } else {
                $valid=$r && match($action) {'accept','reject'=>$r['status']==='pending' && $r['sender']!==$actor,'cancel'=>$r['status']==='pending' && $r['sender']===$actor,'remove'=>$r['status']==='accepted',default=>false};
                if (!$valid) throw new CloudHubSocialError('Stan relacji zmienił się. Odśwież listę.',409);
                if ($action==='accept') { $this->q("UPDATE cloudhub_friendships SET status='accepted' WHERE low_id=? AND high_id=?",$pair); $this->notify($target,$actor,'accepted',$actor); }
                else $this->q('DELETE FROM cloudhub_friendships WHERE low_id=? AND high_id=?',$pair);
                $this->q("UPDATE cloudhub_notifications SET read_at=COALESCE(read_at,UTC_TIMESTAMP(6)) WHERE kind='invite' AND ((recipient=? AND actor=?) OR (recipient=? AND actor=?))",[$actor,$target,$target,$actor]);
            }
        }
        return $this->relation($actor,$target);
    }
    private function audit(string $actor,string $target,string $action,string $reason,array $meta): void {
        $this->q('INSERT INTO cloudhub_admin_audit_logs(actor_steamid,target_steamid,action,reason,metadata_json,created_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6))',[$actor,$target,'social.'.$action,$reason,json_encode($meta,JSON_THROW_ON_ERROR)]);
    }
    private function requireModerator(string $actor): void {
        // Shared locks hold roles and grants for the moderation transaction: a revocation waits, and only SELECT is required.
        $roles=$this->q('SELECT role FROM cloudhub_player_roles WHERE steamid=? LOCK IN SHARE MODE',[$actor])->fetchAll(PDO::FETCH_COLUMN);
        $grants=$this->q("SELECT role FROM cloudhub_role_permissions WHERE permission='moderation.manage' LOCK IN SHARE MODE")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('OWNER',$roles,true) && !array_intersect($roles,$grants)) throw new CloudHubSocialError('Brak uprawnień do moderacji.');
    }
    private function commentAction(string $actor,string $action,array $in): array {
        $id=self::id($in['id']??'');
        $c=$this->q('SELECT * FROM cloudhub_profile_comments WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$c) throw new CloudHubSocialError('Komentarz niedostępny.',404);
        if ($action==='resolve') {
            $this->requireModerator($actor); $reason=self::text($in['reason']??null,500);
            $this->q('UPDATE cloudhub_comment_reports SET resolved_at=UTC_TIMESTAMP(6) WHERE comment_id=? AND resolved_at IS NULL',[$id]);
            $this->audit($actor,$c['profile_id'],'report.resolve',$reason,['comment_id'=>$id]); return ['saved'=>true];
        }
        if ($c['deleted_at']!==null) throw new CloudHubSocialError('Komentarz niedostępny.',404);
        if ($action==='report') {
            $this->visible($c['profile_id'],$actor);
            if ($this->blocked($actor,$c['author'])) throw new CloudHubSocialError('Komentarz niedostępny.',404);
            if ($c['author']===$actor) throw new InvalidArgumentException('Nie możesz zgłosić własnego komentarza.');
            $reason=self::text($in['reason']??null,500); $this->limit($actor,'report',20,3);
            $this->q('INSERT IGNORE INTO cloudhub_comment_reports(comment_id,reporter,reason,created_at) VALUES (?,?,?,UTC_TIMESTAMP(6))',[$id,$actor,$reason]); return ['saved'=>true];
        }
        $moderation=$actor!==$c['author'] && $actor!==$c['profile_id'];
        if ($moderation) { $this->requireModerator($actor); $reason=self::text($in['reason']??null,500); }
        else $reason=$actor===$c['author']?'Usunięcie własnego komentarza':'Usunięcie przez właściciela profilu';
        $this->q('UPDATE cloudhub_profile_comments SET deleted_at=UTC_TIMESTAMP(6) WHERE id=?',[$id]);
        $this->q('UPDATE cloudhub_comment_reports SET resolved_at=UTC_TIMESTAMP(6) WHERE comment_id=? AND resolved_at IS NULL',[$id]);
        $this->q('DELETE FROM cloudhub_notifications WHERE comment_id=?',[$id]);
        $this->audit($actor,$c['profile_id'],$moderation?'comment.moderate':'comment.delete',$reason,['comment_id'=>$id]);
        if ($moderation) $this->notify($c['author'],$actor,'moderated',$c['profile_id'],$id);
        return ['saved'=>true];
    }
    private function cursor(?string $before,string $column,array &$args): string {
        if ($before===null) return ''; $args[]=self::id($before); return " AND $column<?";
    }
    private function page(array $rows): array {
        foreach ($rows as &$r) if (isset($r['id'])) $r['id']=(string)$r['id'];
        $next=count($rows)>20; if ($next) array_pop($rows);
        return ['items'=>$rows,'next_before'=>$next?(string)end($rows)['id']:null];
    }
    public function comments(string $target,?string $viewer,?string $before): array {
        $this->visible($target,$viewer); $args=[$target]; $where=$this->cursor($before,'c.id',$args);
        if ($viewer!==null) { $where.=' AND NOT EXISTS(SELECT 1 FROM cloudhub_blocks b WHERE (b.actor=? AND b.target=c.author) OR (b.target=? AND b.actor=c.author))'; $args[]=$viewer; $args[]=$viewer; }
        $rows=$this->q('SELECT CAST(c.id AS CHAR) id,c.author,c.body,c.created_at,p.nickname,p.avatar_url,f.profile_visibility FROM cloudhub_profile_comments c JOIN cloudhub_players p ON p.steamid=c.author JOIN cloudhub_profiles f ON f.steamid=c.author WHERE c.profile_id=? AND c.deleted_at IS NULL'.$where.' ORDER BY c.id DESC LIMIT 21',$args)->fetchAll(PDO::FETCH_ASSOC);
        $moderator=$viewer!==null && $this->canModerate($viewer);
        // Staff roles are always shown; rank and TOP follow the author's own profile privacy.
        $withStats=array_column(array_filter($rows,fn($r)=>$this->allows($r['profile_visibility'],$r['author'],$viewer)),'author');
        $badges=(new CloudHubCommunity($this->db))->badges(array_column($rows,'author'),$withStats);
        foreach ($rows as &$r) {
            $r['can_delete']=$viewer!==null && ($r['author']===$viewer || $target===$viewer || $moderator); $r['moderation_required']=$r['can_delete'] && $r['author']!==$viewer && $target!==$viewer;
            $r['can_report']=$viewer!==null && $r['author']!==$viewer; $r['badges']=$badges[$r['author']]; unset($r['profile_visibility']);
        }
        unset($r);
        return $this->page($rows);
    }
    public function friendList(string $target,?string $viewer,int $page=1): array {
        if ($page<1 || $page>10000) throw new InvalidArgumentException('Nieprawidłowa strona.');
        $s=$this->visible($target,$viewer);
        if (!$this->allows($s['friends_visibility'],$target,$viewer)) throw new CloudHubSocialError('Lista znajomych jest prywatna.');
        $sql=" FROM cloudhub_friendships f JOIN cloudhub_players p ON p.steamid=IF(f.low_id=?,f.high_id,f.low_id) WHERE (f.low_id=? OR f.high_id=?) AND f.status='accepted'"; $args=[$target,$target,$target];
        // Identity on a deliberately shared friend list is public; profile contents retain independent privacy.
        if ($viewer!==null) { $sql.=' AND NOT EXISTS(SELECT 1 FROM cloudhub_blocks b WHERE (b.actor=? AND b.target=p.steamid) OR (b.target=? AND b.actor=p.steamid))'; $args[]=$viewer; $args[]=$viewer; }
        $total=(int)$this->q('SELECT COUNT(*)'.$sql,$args)->fetchColumn(); $offset=($page-1)*20;
        $items=$this->q('SELECT p.steamid,p.nickname,p.avatar_url'.$sql." ORDER BY p.steamid LIMIT 20 OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);
        // Own list: every row is an accepted friendship (blocks delete friendships), so no per-row relation lookup is needed.
        if ($viewer===$target) foreach ($items as &$item) $item['relation']=['status'=>'friends','blocked_by_me'=>false];
        unset($item);
        return ['items'=>$items,'total'=>$total,'page'=>$page];
    }
    public function connections(string $actor,string $kind,int $page): array {
        if ($page<1 || $page>10000 || !in_array($kind,['received','sent','blocked'],true)) throw new InvalidArgumentException('Nieprawidłowa lista.');
        $offset=($page-1)*20;
        if ($kind==='blocked') { $sql=' FROM cloudhub_blocks b JOIN cloudhub_players p ON p.steamid=b.target WHERE b.actor=?'; $args=[$actor]; }
        else { $sql=" FROM cloudhub_friendships f JOIN cloudhub_players p ON p.steamid=IF(f.low_id=?,f.high_id,f.low_id) WHERE (f.low_id=? OR f.high_id=?) AND f.status='pending' AND f.sender".($kind==='sent'?'=':'<>').'?'; $args=[$actor,$actor,$actor,$actor]; }
        $items=$this->q('SELECT p.steamid,p.nickname,p.avatar_url'.$sql." ORDER BY p.steamid LIMIT 20 OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);
        // The list kind is the relation: blocks remove friendships, pending rows are directional.
        foreach ($items as &$item) $item['relation']=['status'=>$kind,'blocked_by_me'=>$kind==='blocked'];
        unset($item);
        return ['items'=>$items,'total'=>(int)$this->q('SELECT COUNT(*)'.$sql,$args)->fetchColumn(),'page'=>$page];
    }
    public function notifications(string $actor,?string $before): array {
        $args=[$actor]; $where=$this->cursor($before,'n.id',$args);
        $rows=$this->q('SELECT CAST(n.id AS CHAR) id,n.kind,n.profile_id,n.created_at,n.read_at FROM cloudhub_notifications n WHERE n.recipient=?'.$where.' ORDER BY n.id DESC LIMIT 21',$args)->fetchAll(PDO::FETCH_ASSOC);
        // Never embed comment text, private profile details or a moderator identity.
        return $this->page($rows)+['unread'=>(int)$this->q('SELECT COUNT(*) FROM cloudhub_notifications WHERE recipient=? AND read_at IS NULL',[$actor])->fetchColumn()];
    }
    public function moderation(string $actor,?string $before): array {
        if (!$this->canModerate($actor)) throw new CloudHubSocialError('Brak uprawnień do moderacji.');
        $args=[]; $where=$this->cursor($before,'r.id',$args);
        $rows=$this->q('SELECT CAST(r.id AS CHAR) id,CAST(r.comment_id AS CHAR) comment_id,r.reason,r.reporter,r.created_at,c.body,c.author,c.profile_id FROM cloudhub_comment_reports r JOIN cloudhub_profile_comments c ON c.id=r.comment_id WHERE r.resolved_at IS NULL'.$where.' ORDER BY r.id DESC LIMIT 21',$args)->fetchAll(PDO::FETCH_ASSOC);
        return $this->page($rows);
    }
    public function auditList(string $actor,?string $before): array {
        if (!$this->canModerate($actor)) throw new CloudHubSocialError('Brak uprawnień do moderacji.');
        $args=[]; $where=$this->cursor($before,'id',$args);
        return $this->page($this->q("SELECT CAST(id AS CHAR) id,actor_steamid,target_steamid,action,reason,metadata_json,created_at FROM cloudhub_admin_audit_logs WHERE action LIKE 'social.%'".$where.' ORDER BY id DESC LIMIT 21',$args)->fetchAll(PDO::FETCH_ASSOC));
    }
}
