<?php
require_once __DIR__.'/../backend/class/CloudHubCommunity.php';
$community=new CloudHubCommunity($db);
$startChecks=$n;
$db->beginTransaction();
try {
    $db->exec('UPDATE cloudhub_player_stats SET rp=0,kills=0,deaths=0');
    $db->exec("UPDATE cloudhub_player_stats SET rp=4500,kills=10 WHERE steamid IN ('$one','$two')");
    $board=$community->leaderboard(null,'','rp',10,1);
    $check($board['players'][0]['steamid']===$one && $board['players'][1]['steamid']===$two,'Tie uses SteamID');
    $check($board['players'][0]['rank']['rank_key']==='diamond' && $board['players'][0]['roles']===['OWNER'] && $board['players'][0]['position']==='1','Role, rank and TOP independent');
    $search=$community->leaderboard(null,'Other','kills',10,1);
    $check($search['players'][0]['position']==='2','Search does not renumber TOP');
    $check($board['players'][0]['kd']==='10.00','Zero deaths use denominator one');
    $check(!array_key_exists('balance',$board['players'][0]),'Public ranking has no balance');
    $check($community->profile($one,null)['last_seen_at']===null,'Activity private by default');
    $community->edit($two,['bio'=>'<script>literal text</script>','profile_visibility'=>'private','activity_visibility'=>'private']);
    $check($community->profile($two,null)===null,'Private profile hidden');
    $check($community->profile($two,$two)['bio']==='<script>literal text</script>','Owner can read own bio');
    $check($community->profile($two,$one)['bio']===null,'OWNER does not bypass bio privacy');
    $check(count($community->leaderboard(null,'Other','rp',10,1)['players'])===0,'Private players hidden from search');
    $reject(fn()=>$community->edit($two,['bio'=>'','rp'=>'100','profile_visibility'=>'public','activity_visibility'=>'public']),'Reject stat edits');
    $reject(fn()=>$community->edit($two,['bio'=>'','profile_visibility'=>'friends','activity_visibility'=>'public']),'Friends not implemented');
    $reject(fn()=>$community->leaderboard(null,'','rp DESC; DROP TABLE',10,1),'Sort whitelist');
    $check($community->leaderboard(null,"%' OR 1=1 --",'rp',10,1)['players']===[],'Search SQL injection rejected as literal');
    $check($community->leaderboard(null,'','rp',10,999)['players']===[],'Empty page');
    $reject(fn()=>$community->leaderboard(null,'','rp',999,1),'Bound page size');
    $db->exec("INSERT INTO cloudhub_seasons(name,starts_at,ends_at,status,policy_json) VALUES ('Test history','2020-01-01','2020-02-01','closed','{}')");
    $season=(string)$db->lastInsertId();
    $db->prepare('INSERT INTO cloudhub_player_season_stats(season_id,steamid,rp,final_position) VALUES (?,?,500,3)')->execute([$season,$one]);
    $check($community->leaderboard($season,'','rp',10,1)['players'][0]['rank']['rank_key']==='silver','Season ranking uses season RP');
    $check($community->profile($one,null)['seasons'][0]['final_position']==='3','Historical final position preserved');
} finally { $db->rollBack(); }
fwrite(STDERR,'CloudHub community assertions passed: '.($n-$startChecks)."\n");
