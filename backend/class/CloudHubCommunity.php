<?php
require_once __DIR__ . '/CloudHubAccounts.php';
/** Public projections never select wallet, verification or moderation data. */
final class CloudHubCommunity {
    public function __construct(private PDO $db) {}
    private function query(string $sql, array $args=[]): PDOStatement {
        $stmt=$this->db->prepare($sql); $stmt->execute($args); return $stmt;
    }
    public static function positiveId(string $id): string {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D',$id) || strlen($id)===19 && strcmp($id,'9223372036854775807')>0) throw new InvalidArgumentException('Invalid ID');
        return $id;
    }
    /** Rank is always RP DESC, kills DESC, SteamID64 ASC, before search/filter/pagination. */
    private function ranked(?string $season): array {
        if ($season===null) return ['SELECT s.*, ROW_NUMBER() OVER (ORDER BY rp DESC,kills DESC,steamid ASC) position FROM cloudhub_player_stats s',[]];
        self::positiveId($season);
        return ['SELECT s.*, ROW_NUMBER() OVER (ORDER BY rp DESC,kills DESC,steamid ASC) position FROM cloudhub_player_season_stats s WHERE season_id=?',[$season]];
    }
    private function decorate(array $rows): array {
        if (!$rows) return [];
        $ranks=$this->db->query('SELECT rank_key,name,min_rp,color,icon FROM cloudhub_rank_config ORDER BY min_rp DESC')->fetchAll(PDO::FETCH_ASSOC);
        $ids=array_column($rows,'steamid');
        $roles=$this->query('SELECT steamid,role FROM cloudhub_player_roles WHERE steamid IN ('.implode(',',array_fill(0,count($ids),'?')).") AND role IN ('OWNER','ADMIN','MODERATOR')",$ids)->fetchAll(PDO::FETCH_ASSOC);
        $byId=[]; foreach ($roles as $r) $byId[$r['steamid']][]=$r['role'];
        foreach ($rows as &$row) {
            foreach (['rp','kills','deaths','assists','headshots','rounds_played','rounds_won','rounds_lost','play_seconds','position','season_id','final_position'] as $key) if (isset($row[$key])) $row[$key]=(string)$row[$key];
            $row['kd']=number_format((float)$row['kills']/max(1,(float)$row['deaths']),2,'.','');
            $row['win_rate']=(float)$row['rounds_played']>0 ? number_format(100*(float)$row['rounds_won']/(float)$row['rounds_played'],2,'.','') : null;
            $row['roles']=array_values(array_intersect(['OWNER','ADMIN','MODERATOR'],$byId[$row['steamid']]??[]));
            $row['rank']=null;
            foreach ($ranks as $rank) if ((int)$row['rp'] >= (int)$rank['min_rp']) { $rank['min_rp']=(string)$rank['min_rp']; $row['rank']=$rank; break; }
        }
        return $rows;
    }
    public function seasons(): array {
        $rows=$this->db->query("SELECT id,name,starts_at,ends_at,status FROM cloudhub_seasons WHERE status IN ('active','closed') ORDER BY starts_at DESC,id DESC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) $row['id']=(string)$row['id'];
        return $rows;
    }
    public function leaderboard(?string $season, string $search, string $sort, int $limit, int $page, bool $admin=false): array {
        $sorts=['rp'=>'r.rp','kills'=>'r.kills','kd'=>'r.kills/GREATEST(r.deaths,1)','headshots'=>'r.headshots','win_rate'=>'r.rounds_won/NULLIF(r.rounds_played,0)'];
        if (!isset($sorts[$sort]) || !in_array($limit,[10,50,100],true) || $page<1 || $page>10000 || strlen($search)>128 || preg_match('//u',$search)!==1) throw new InvalidArgumentException('Invalid leaderboard parameters');
        [$ranked,$args]=$this->ranked($season);
        $where=$admin?'1=1':"f.profile_visibility='public'";
        if ($search!=='') { $where.=" AND (p.nickname LIKE ? ESCAPE '!' OR p.steamid=?)"; $args[]='%'.strtr($search,['!'=>'!!','%'=>'!%','_'=>'!_']).'%'; $args[]=$search; }
        $from=" FROM ($ranked) r JOIN cloudhub_players p USING(steamid) JOIN cloudhub_profiles f USING(steamid)";
        if ($admin) $from.=' JOIN cloudhub_wallets w USING(steamid)';
        $total=(int)$this->query("SELECT COUNT(*) $from WHERE $where",$args)->fetchColumn();
        $offset=($page-1)*$limit;
        $rows=$this->query('SELECT r.*,p.nickname,p.avatar_url'.($admin?',CAST(w.balance AS CHAR) balance':'')." $from WHERE $where ORDER BY {$sorts[$sort]} DESC,r.rp DESC,r.kills DESC,r.steamid ASC LIMIT $limit OFFSET $offset",$args)->fetchAll(PDO::FETCH_ASSOC);
        return ['players'=>$this->decorate($rows),'total'=>$total,'page'=>$page,'limit'=>$limit];
    }
    public function profile(string $id, ?string $viewer): ?array {
        CloudHubAccounts::steamId($id);
        $profile=$this->query('SELECT p.steamid,p.nickname,p.avatar_url,p.last_seen_at,f.bio,f.profile_visibility,f.activity_visibility FROM cloudhub_players p JOIN cloudhub_profiles f USING(steamid) WHERE p.steamid=?',[$id])->fetch(PDO::FETCH_ASSOC);
        if (!$profile) return null;
        $own=$viewer===$id;
        $admin=$viewer!==null && in_array('OWNER',(new CloudHubAccounts($this->db))->roles($viewer),true);
        if (!$own && $profile['profile_visibility']!=='public' && !$admin) return null;
        // OWNER may inspect statistics but does not bypass private bio/activity.
        if (!$own && $profile['profile_visibility']!=='public') $profile['bio']=null;
        if (!$own && $profile['activity_visibility']!=='public') $profile['last_seen_at']=null;
        [$ranked,$args]=$this->ranked(null);
        $stats=$this->query("SELECT r.* FROM ($ranked) r WHERE steamid=?",[$id])->fetch(PDO::FETCH_ASSOC);
        $profile['stats']=$stats?$this->decorate([$stats])[0]:null;
        $history=$this->query("SELECT s.*,c.name,c.status FROM cloudhub_player_season_stats s JOIN cloudhub_seasons c ON c.id=s.season_id WHERE s.steamid=? AND c.status IN ('active','closed') ORDER BY c.starts_at DESC,c.id DESC",[$id])->fetchAll(PDO::FETCH_ASSOC);
        // final_position is historical only when Core has explicitly recorded it.
        $profile['seasons']=$this->decorate($history);
        $profile['editable']=$own;
        return $profile;
    }
    public function edit(string $actor, array $input): void {
        CloudHubAccounts::steamId($actor);
        if (array_diff(array_keys($input),['bio','profile_visibility','activity_visibility'])) throw new InvalidArgumentException('Unsupported profile field');
        $bio=$input['bio']??null;
        if (!is_string($bio) || preg_match('//u',$bio)!==1 || preg_match_all('/./us',$bio)>1000 || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$bio)) throw new InvalidArgumentException('Invalid bio (max 1000 characters)');
        foreach (['profile_visibility','activity_visibility'] as $key) if (!in_array($input[$key]??null,['public','private'],true)) throw new InvalidArgumentException('Invalid privacy setting');
        if (!$this->query('SELECT 1 FROM cloudhub_profiles WHERE steamid=?',[$actor])->fetchColumn()) throw new InvalidArgumentException('Sign in through Steam again');
        $this->query('UPDATE cloudhub_profiles SET bio=?,profile_visibility=?,activity_visibility=? WHERE steamid=?',[$bio,$input['profile_visibility'],$input['activity_visibility'],$actor]);
    }
}
