using CloudHub.Core;
using System.Net;
using System.Text.Json;
int checks=0;
void Check(bool condition,string name){if(!condition)throw new Exception(name);checks++;Console.WriteLine("PASS "+name);}
async Task Until(Func<bool> ready){var end=DateTime.UtcNow.AddSeconds(8);while(!ready() && DateTime.UtcNow<end)await Task.Delay(20);Check(ready(),"async operation completes");}
var roster=Enumerable.Range(0,4).Select(i=>new PlayerSnapshot($"7656119800000030{i}",i<2?2:3,true,0,0,0,0,0)).ToArray();
var tracker=new RoundTracker();
RoundEvent Round(){tracker.Start(0,roster,false);for(int t=1;t<=60;t++)tracker.Sample(t,roster.Select(p=>p with{X=t*10}).ToArray(),false);return tracker.Finish(60,1700000000,2,9)!;}
var e=Round();Check(e.Players.All(p=>p.ActiveSeconds==60),"movement activity");Check(e.EventId==e.RoundId,"stable round identity");Check(tracker.Finish(60,1700000000,2,9) is null,"duplicate round end ignored");
tracker.Start(0,roster,true);Check(tracker.Finish(60,1700000000,2,9) is null,"warmup/bot rounds ignored");
tracker.Start(0,roster,false);tracker.Sample(1,roster,true);Check(tracker.Finish(60,1700000000,2,9) is null,"bot or warmup transition invalidates");
tracker.Start(0,roster,false);tracker.Sample(6,roster,false);Check(tracker.Finish(60,1700000000,2,9) is null,"sampling gap invalidates");
tracker.Start(0,roster,false);tracker.Sample(1,roster.Select((p,i)=>i==0?p with{Team=3}:p).ToArray(),false);Check(tracker.Finish(60,1700000000,2,9) is null,"team change invalidates");
tracker.Start(0,roster,false);tracker.Sample(1,roster.Append(roster[0] with{Steamid="76561198000000999"}).ToArray(),false);Check(tracker.Finish(60,1700000000,2,9) is null,"late join invalidates");
tracker.Start(0,roster,false);tracker.Died(roster[0].Steamid,roster[2].Steamid,roster[1].Steamid,true);tracker.Died(roster[0].Steamid,roster[2].Steamid,roster[1].Steamid,true);var combat=tracker.Finish(60,1700000000,2,9)!;Check(combat.Deaths.Length==1 && combat.Deaths[0].Headshot && combat.Deaths[0].Assister==roster[1].Steamid,"deduplicated kill/headshot/assist");
tracker.Start(0,roster,false);tracker.Died(null,roster[2].Steamid,null,false);Check(tracker.Finish(60,1700000000,2,9)!.Deaths[0].Killer is null,"world death");
tracker.Start(0,roster,false);tracker.Died(roster[0].Steamid,roster[2].Steamid,null,false);tracker.Spawned(roster[2].Steamid);Check(tracker.Finish(60,1700000000,2,9) is null,"respawn mode invalidates");
tracker.Start(0,roster,false);tracker.Disconnected(roster[0].Steamid);tracker.Sample(1,roster,false);Check(!tracker.Finish(60,1700000000,2,9)!.Players[0].Finished,"reconnect cannot restore participation");
tracker.Start(0,roster,false);for(int t=1;t<=60;t++)tracker.Sample(t,roster,false);Check(tracker.Finish(60,1700000000,2,9)!.Players.All(p=>p.ActiveSeconds==0),"AFK no activity");
tracker.Start(0,roster,false);for(int shot=0;shot<100;shot++)tracker.Fired(roster[0].Steamid);tracker.Sample(1,roster,false);tracker.Sample(2,roster,false);Check(tracker.Finish(60,1700000000,2,9)!.Players[0].ActiveSeconds==1,"fire activity bounded by elapsed time");
foreach(var reason in new[]{0,10,16,17,18,19,20})Check(!Protocol.ValidOutcome(2,reason),"no inferred outcome "+reason);
Check(Protocol.ValidOutcome(3,11) && Protocol.ValidOutcome(2,13),"hostage outcome mapping CSS376");
tracker.Start(0,roster,false);tracker.Cancel();Check(tracker.Finish(60,1700000000,2,9) is null,"map/restart cancels");
var vector=JsonDocument.Parse(File.ReadAllText(args.Length>0?args[0]:"tests/protocol-vector.json")).RootElement;
string V(string name)=>vector.GetProperty(name).GetString()!;
Check(EventSigner.Sign(V("key"),V("server"),V("timestamp"),V("body"))==V("signature"),"shared UTF-8 HMAC vector");
Check(EventSigner.Sign(V("key"),V("server"),V("timestamp"),V("body")+" ")!=V("signature"),"body integrity");
var directory=Path.Combine(Path.GetTempPath(),"cloudhub-outbox-"+Guid.NewGuid());
var endpoint=new Uri("https://ingest.invalid/api/ingest.php");var key=V("key");
try {
    bool rejected=false;try{await using var bad=new DurableOutbox(directory,new Uri("http://ingest.invalid"),"test",key,_=>{});}catch(ArgumentException){rejected=true;}Check(rejected,"insecure HTTP configuration rejected");
    var unavailable=new FakeHandler(_=>new(HttpStatusCode.ServiceUnavailable));
    await using(var box=new DurableOutbox(directory,endpoint,"test",key,_=>{},unavailable,TimeSpan.FromMilliseconds(20))){Check(!box.TryEnqueue(e with {EventId="../escape"}),"outbox rejects unsafe identity");Check(box.TryEnqueue(e),"bounded admission");await Until(()=>Directory.GetFiles(directory,"*.json").Length==1);await Until(()=>unavailable.Calls>0);Check(File.ReadAllText(Directory.GetFiles(directory,"*.json")[0])==Protocol.Encode(e),"exact payload persisted during outage");}
    var ack=new FakeHandler(r=>{
        var body=r.Content!.ReadAsStringAsync().GetAwaiter().GetResult();var stamp=r.Headers.GetValues("X-CloudHub-Timestamp").Single();
        if(r.Headers.GetValues("X-CloudHub-Signature").Single()!=EventSigner.Sign(key,"test",stamp,body))throw new Exception("bad signature");
        return new(HttpStatusCode.OK){Content=new StringContent("{\"status\":\"duplicate\"}")};
    });
    await using(var restarted=new DurableOutbox(directory,endpoint,"test",key,_=>{},ack,TimeSpan.FromMilliseconds(20))){await Until(()=>ack.Calls>0 && Directory.GetFiles(directory,"*.json").Length==0);Check(ack.Calls>=1,"restart resends and duplicate ack removes record");}
    var e2=Round();File.WriteAllText(Path.Combine(directory,e2.EventId+".json.tmp"),Protocol.Encode(e2));
    var conflict=new FakeHandler(_=>new(HttpStatusCode.Conflict));
    await using(var box=new DurableOutbox(directory,endpoint,"test",key,_=>{},conflict,TimeSpan.FromMilliseconds(20))){await Until(()=>Directory.GetFiles(directory,"*.rejected").Length==1);Check(Directory.GetFiles(directory,"*.json").Length==0,"interrupted write recovered then conflict quarantined");}
    var e3=Round();var badAck=new FakeHandler(_=>new(HttpStatusCode.OK){Content=new StringContent("{\"status\":\"unknown\"}")});
    await using(var box=new DurableOutbox(directory,endpoint,"test",key,_=>{},badAck,TimeSpan.FromMilliseconds(20))){box.TryEnqueue(e3);await Until(()=>badAck.Calls>=2);Check(Directory.GetFiles(directory,"*.json").Length==1,"invalid ack does not delete record");}
    var oversize=new FakeHandler(_=>new(HttpStatusCode.OK){Content=new StringContent(new string('x',5000))});
    await using(var box=new DurableOutbox(directory,endpoint,"test",key,_=>{},oversize,TimeSpan.FromMilliseconds(20))){await Until(()=>oversize.Calls>=2);Check(Directory.GetFiles(directory,"*.json").Length==1,"oversized ack does not delete record");}
} finally {Directory.Delete(directory,true);}
// Ownership for the in-game menus: signed replies, fail-closed rules, background refresh.
Check(EventSigner.SignResponse(V("key"),V("server"),V("timestamp"),V("body"))==V("response_signature"),"shared reply HMAC vector");
Check(EventSigner.SignResponse(V("key"),V("server"),V("timestamp"),V("body"))!=V("signature"),"reply signature differs from request signature");
const ulong P1=76561198000000901,P2=76561198000000902;
long unixNow=DateTimeOffset.UtcNow.ToUnixTimeSeconds();
string Reply(string nonce,string json)=>"{\"v\":1,\"request\":\""+nonce+"\",\"server\":\"test\","+json+"}";
string Stamp()=>DateTimeOffset.UtcNow.ToUnixTimeSeconds().ToString(System.Globalization.CultureInfo.InvariantCulture);
const string Enforced="\"enforced\":true,\"generated_at\":1,\"players\":{\"76561198000000901\":[[7,44],[507,38]],\"76561198000000902\":[]},\"models\":[507,5030],\"prices\":[[7,44,900],[7,180,1200],[507,38,9000],[507,12,7000],[5030,10010,4000]],\"purchases_enabled\":true";
OwnershipReply Parsed(string body,string nonce="n1",ulong[]? ids=null,bool catalog=true,string? stamp=null,string? sig=null,string server="test") {
    stamp??=Stamp();
    return OwnershipProtocol.Parse(body,stamp,sig??EventSigner.SignResponse(key,"test",stamp,body),key,server,nonce,ids??[P1,P2],catalog,DateTimeOffset.UtcNow.ToUnixTimeSeconds());
}
bool Rejects(Func<OwnershipReply> f){try{f();return false;}catch(Exception ex) when(ex is FormatException or JsonException){return true;}}
var good=Reply("n1",Enforced);
var parsed=Parsed(good);
Check(parsed.Enforced && parsed.Players[P1].Contains((7,44)) && parsed.Players[P2].Count==0 && parsed.Catalog!.ModelPrices[507]==7000,"signed reply parsed with cheapest model price");
Check(Rejects(()=>Parsed(good,sig:new string('0',64))),"forged reply signature rejected");
Check(Rejects(()=>Parsed(good,sig:EventSigner.Sign(key,"test",Stamp(),good))),"request-style signature on a reply rejected");
Check(Rejects(()=>Parsed(good,stamp:(unixNow-900).ToString(System.Globalization.CultureInfo.InvariantCulture))),"stale reply rejected");
Check(Rejects(()=>Parsed(good,nonce:"n2")),"reply to another request rejected");
Check(Rejects(()=>Parsed(good,server:"other")),"reply for another server rejected");
Check(Rejects(()=>Parsed(good,ids:[P1,P2,76561198000000903])),"reply missing a requested player rejected");
Check(Rejects(()=>Parsed(Reply("n1","\"enforced\":\"false\",\"players\":{}"))),"enforced must be a JSON boolean");
Check(Rejects(()=>Parsed(Reply("n1",Enforced.Replace("\"prices\":","\"x\":")))),"reply without requested catalog rejected");
Check(Rejects(()=>Parsed("<html>maintenance</html>")) && Rejects(()=>Parsed("{}")),"captive or empty pages rejected");
var notEnforced=Parsed(Reply("n1","\"enforced\":false,\"generated_at\":1,\"players\":{}"));
Check(!notEnforced.Enforced && notEnforced.Players.Count==0 && notEnforced.Catalog is null,"explicit not-enforced reply");

var t0=DateTimeOffset.UtcNow;
var cache=new OwnershipCache(TimeSpan.FromMinutes(2),TimeSpan.FromHours(6));
Check(cache.Check(P1,7,44,t0).Status==Ownership.Pending && cache.Check(P1,507,0,t0).Status==Ownership.Pending,"no data: paid items pending (locked)");
Check(cache.Check(P1,0,0,t0).Status==Ownership.Free,"default gloves free without data");
cache.Apply(parsed,t0);
Check(cache.Check(P1,7,44,t0)==OwnershipCheck.Owned,"owned finish allowed");
Check(cache.Check(P1,7,180,t0)==new OwnershipCheck(Ownership.Locked,1200),"unowned finish locked with database price");
Check(cache.Check(P1,7,99999,t0)==new OwnershipCheck(Ownership.Locked,null),"finish outside the shop locked without price");
Check(cache.Check(P1,7,0,t0)==OwnershipCheck.Free && cache.Check(P2,7,0,t0)==OwnershipCheck.Free,"default finish free");
Check(cache.Check(P1,507,0,t0)==OwnershipCheck.Owned,"knife model unlocked by an owned finish");
Check(cache.Check(P2,507,0,t0)==new OwnershipCheck(Ownership.Locked,7000) && cache.Check(P2,5030,0,t0)==new OwnershipCheck(Ownership.Locked,4000),"unowned knife and glove models locked from cheapest finish");
Check(cache.Check(P1,507,12,t0)==new OwnershipCheck(Ownership.Locked,7000),"owning one knife finish does not unlock others");
Check(cache.Check(P1,-1,0,t0).Status==Ownership.Locked,"unknown knife locked");
Check(cache.Check(76561198000000903,7,44,t0).Status==Ownership.Pending,"player never loaded stays pending");
Check(cache.Check(P1,7,44,t0.AddHours(7)).Status==Ownership.Pending,"owned data has a maximum age");
var noSales=new OwnershipCache(TimeSpan.FromMinutes(2),TimeSpan.FromHours(6));
noSales.Apply(Parsed(Reply("n1",Enforced.Replace("\"purchases_enabled\":true","\"purchases_enabled\":false"))),t0);
Check(noSales.Check(P2,7,44,t0)==new OwnershipCheck(Ownership.Locked,null),"no price offered while purchases are off");
var offCache=new OwnershipCache(TimeSpan.FromMinutes(2),TimeSpan.FromHours(6));
offCache.Apply(notEnforced,t0);
Check(offCache.Check(P2,7,180,t0.AddMinutes(1))==OwnershipCheck.Free,"fresh explicit not-enforced reply allows everything");
Check(offCache.Check(P2,7,180,t0.AddMinutes(3)).Status==Ownership.Pending,"expired not-enforced reply locks paid items again");
cache.Apply(notEnforced,t0.AddMinutes(1));cache.Apply(parsed,t0.AddMinutes(2));
Check(cache.Check(P2,7,180,t0.AddMinutes(2)).Status==Ownership.Locked,"enforcement switched back on applies immediately");

// Service: background fetch, signed request, outage keeps last-known-good, never unlocks.
var clock=new ManualClock(t0);
Func<HttpRequestMessage,HttpResponseMessage> mode=_=>new(HttpStatusCode.ServiceUnavailable);
var requests=new List<JsonElement>();
HttpResponseMessage SignedReply(HttpRequestMessage r,string json){
    var body=r.Content!.ReadAsStringAsync().GetAwaiter().GetResult();var stamp=r.Headers.GetValues("X-CloudHub-Timestamp").Single();
    if(r.Headers.GetValues("X-CloudHub-Signature").Single()!=EventSigner.Sign(key,"test",stamp,body))throw new Exception("bad request signature");
    var req=JsonDocument.Parse(body).RootElement.Clone();lock(requests)requests.Add(req);
    var ids=req.GetProperty("steamids").EnumerateArray().Select(x=>x.GetString()!).ToArray();
    var players=string.Join(",",ids.Select(id=>"\""+id+"\":"+(id=="76561198000000901"?"[[7,44]]":"[]")));
    var reply=Reply(req.GetProperty("request").GetString()!,json.Replace("{PLAYERS}",players));var now=clock.GetUtcNow().ToUnixTimeSeconds().ToString(System.Globalization.CultureInfo.InvariantCulture);
    var response=new HttpResponseMessage(HttpStatusCode.OK){Content=new StringContent(reply)};
    response.Headers.Add("X-CloudHub-Timestamp",now);response.Headers.Add("X-CloudHub-Signature",EventSigner.SignResponse(key,"test",now,reply));
    return response;
}
const string Live="\"enforced\":true,\"players\":{{PLAYERS}},\"models\":[507],\"prices\":[[7,44,900],[7,180,1200]],\"purchases_enabled\":true";
var handler=new FakeHandler(r=>mode(r));
var fast=new OwnershipOptions{Poll=TimeSpan.FromMilliseconds(10),PlayerRefresh=TimeSpan.Zero};
var logs=new List<string>();
await using(var service=new OwnershipService(endpoint,"test",key,m=>{lock(logs)logs.Add(m);},handler,clock,fast)) {
    Check(service.Check(P1,7,44).Status==Ownership.Pending,"service starts pending");
    await Until(()=>handler.Calls>=2);
    Check(service.Check(P1,7,44).Status==Ownership.Pending && service.Check(P1,7,0).Status==Ownership.Pending,"backend down: nothing paid unlocks");
    mode=r=>{var x=new HttpResponseMessage(HttpStatusCode.OK){Content=new StringContent(Reply("x","\"enforced\":false,\"players\":{}"))};x.Headers.Add("X-CloudHub-Timestamp",Stamp());x.Headers.Add("X-CloudHub-Signature",new string('a',64));return x;};
    var calls=handler.Calls;await Until(()=>handler.Calls>calls+1);
    Check(service.Check(P1,7,180).Status==Ownership.Pending,"unsigned not-enforced reply never unlocks");
    mode=r=>SignedReply(r,Live);
    service.Refresh(P1);service.Refresh(P2);
    await Until(()=>service.Check(P1,7,44)==OwnershipCheck.Owned);
    Check(service.Check(P2,7,44)==new OwnershipCheck(Ownership.Locked,900),"service locks unowned with price");
    lock(requests)Check(requests.Any(q=>q.GetProperty("catalog").GetBoolean()) && requests.All(q=>q.GetProperty("steamids").GetArrayLength()<=OwnershipProtocol.MaxPlayers),"signed batched requests with catalog");
    mode=_=>new(HttpStatusCode.ServiceUnavailable);
    service.Refresh(P1);calls=handler.Calls;await Until(()=>handler.Calls>calls+1);
    Check(service.Check(P1,7,44)==OwnershipCheck.Owned && service.Check(P2,7,180).Status==Ownership.Locked,"outage keeps last-known-good ownership and locks");
    lock(logs)Check(logs.Count(l=>l.StartsWith("Ownership data unavailable",StringComparison.Ordinal))>=1,"outage logged once per streak");
    clock.Advance(TimeSpan.FromHours(7));
    Check(service.Check(P1,7,44).Status==Ownership.Pending,"long outage: owned data expires to pending, never to free");
    mode=r=>SignedReply(r,"\"enforced\":false,\"players\":{}");
    await Until(()=>service.Check(P2,7,180)==OwnershipCheck.Free);
    Check(true,"explicit signed not-enforced reply allows items");
    mode=_=>new(HttpStatusCode.ServiceUnavailable);
    clock.Advance(TimeSpan.FromMinutes(3));
    Check(service.Check(P2,7,180).Status==Ownership.Pending,"not-enforced expires during outage; paid items lock again");
    Check(service.Check(123,7,44).Status==Ownership.Pending && service.Check(123,0,0).Status==Ownership.Free,"invalid SteamID never owns anything");
}
Console.WriteLine($"CloudHub C# assertions passed: {checks}");
sealed class FakeHandler(Func<HttpRequestMessage,HttpResponseMessage> reply):HttpMessageHandler {
    private int calls;
    public int Calls=>Volatile.Read(ref calls);
    protected override Task<HttpResponseMessage> SendAsync(HttpRequestMessage request,CancellationToken token){Interlocked.Increment(ref calls);return Task.FromResult(reply(request));}
}
sealed class ManualClock(DateTimeOffset start):TimeProvider {
    private long ticks=start.UtcTicks;
    public override DateTimeOffset GetUtcNow()=>new(Interlocked.Read(ref ticks),TimeSpan.Zero);
    public void Advance(TimeSpan by)=>Interlocked.Add(ref ticks,by.Ticks);
}
