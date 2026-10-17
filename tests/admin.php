<?php
require_once __DIR__.'/../backend/class/CloudHubAdmin.php';
$admin=new CloudHubAdmin($db); $startChecks=$n;
$pipes=[];
$proc=proc_open([PHP_BINARY,__DIR__.'/admin-worker.php','disabled'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
fclose($pipes[0]); $disabledResult=stream_get_contents($pipes[1]); fclose($pipes[1]); fclose($pipes[2]);
$check(proc_close($proc)===0 && $disabledResult==='disabled','CC feature flag fails closed for OWNER');
$input=['steamid'=>$two,'amount'=>'25','reason'=>'Isolated test','request_id'=>'10000000-0000-4000-8000-000000000001'];
$reject(fn()=>$admin->adjust($two,$input),'Player cannot mint coins');
$db->exec("INSERT INTO cloudhub_player_roles(steamid,role) VALUES ('$two','ADMIN')");
$reject(fn()=>$admin->adjust($two,$input),'ADMIN cannot mint coins');
$db->exec("DELETE FROM cloudhub_player_roles WHERE steamid='$two' AND role='ADMIN'");
$result=$admin->adjust($one,$input);
$check($result['balance']==='25','Credit committed');
$check($admin->adjust($one,$input)['status']==='duplicate','Replay deduplicated');
$reject(fn()=>$admin->adjust($one,array_replace($input,['amount'=>'26'])),'Changed payload conflict');
foreach (['0','-0','1.1','1e3','1000001','9223372036854775807'] as $bad) $reject(fn()=>$admin->adjust($one,array_replace($input,['amount'=>$bad])),'Amount bounds');
$reject(fn()=>$admin->adjust($one,array_replace($input,['reason'=>' '])),'Reason mandatory');
$debit=array_replace($input,['amount'=>'-26','request_id'=>'10000000-0000-4000-8000-000000000002']);
$reject(fn()=>$admin->adjust($one,$debit),'Insufficient balance');
$check($wallet($two)===25,'Insufficient debit leaves balance unchanged');
$db->exec("CREATE TRIGGER test_audit_failure BEFORE INSERT ON cloudhub_admin_audit_logs FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test rollback'");
try { $reject(fn()=>$admin->adjust($one,array_replace($debit,['amount'=>'-1'])),'Audit error rolls back whole transaction'); }
finally { $db->exec('DROP TRIGGER test_audit_failure'); }
$check($wallet($two)===25,'Audit failure rolls back wallet');
$check((int)$db->query("SELECT COUNT(*) FROM cloudhub_wallet_transactions WHERE source='admin'")->fetchColumn()===1,'Audit failure rolls back ledger');
// Independent PHP connections, not merely simultaneous calls in a single session.
$runWorkers=function(array $inputs) {
    $workers=[];
    foreach ($inputs as $input) {
        $pipes=[]; $proc=proc_open([PHP_BINARY,__DIR__.'/admin-worker.php',json_encode($input)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        fclose($pipes[0]); $workers[]=[$proc,$pipes];
    }
    $results=[];
    foreach ($workers as [$proc,$pipes]) { $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($proc)!==0) throw new RuntimeException($err); $results[]=json_decode($out,true,512,JSON_THROW_ON_ERROR)['status']; }
    sort($results); return $results;
};
$concurrent=array_replace($debit,['amount'=>'-20']);
$check($runWorkers([$concurrent,$concurrent])===['applied','duplicate'],'Concurrent same UUID applied once');
$check($wallet($two)===5,'Concurrent replay exact balance');
$x=array_replace($debit,['amount'=>'-4','request_id'=>'10000000-0000-4000-8000-000000000003']);
$y=array_replace($x,['request_id'=>'10000000-0000-4000-8000-000000000004']);
$check($runWorkers([$x,$y])===['applied','conflict'],'Concurrent debits cannot overspend');
$check($wallet($two)===1,'Concurrent distinct debits exact balance');
$check((int)$db->query("SELECT COUNT(*) FROM cloudhub_admin_audit_logs WHERE actor_steamid='$one' AND action='wallet.adjust'")->fetchColumn()===3,'Each committed adjustment audited once');
$db->exec("DELETE FROM cloudhub_player_roles WHERE steamid='$one' AND role='OWNER'");
$reject(fn()=>$admin->adjust($one,$input),'Revocation immediate including replay');
$db->exec("INSERT INTO cloudhub_player_roles(steamid,role) VALUES ('$one','OWNER')");
// Leave legacy fixtures unchanged for existing API expectations.
$db->exec("DELETE FROM cloudhub_admin_audit_logs WHERE action='wallet.adjust'");
$db->exec("DELETE FROM cloudhub_wallet_transactions WHERE source='admin'");
$db->exec("UPDATE cloudhub_wallets SET balance=0 WHERE steamid='$two'");
fwrite(STDERR,'CloudHub admin assertions passed: '.($n-$startChecks)."\n");
