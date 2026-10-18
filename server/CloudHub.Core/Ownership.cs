using System.Collections.Concurrent;
using System.Globalization;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
namespace CloudHub.Core;
public enum Ownership { Free, Owned, Locked, Pending }
public readonly record struct OwnershipCheck(Ownership Status,int? PriceCc) {
    public bool Allowed=>Status is Ownership.Free or Ownership.Owned;
    public static readonly OwnershipCheck Free=new(Ownership.Free,null),Owned=new(Ownership.Owned,null),Pending=new(Ownership.Pending,null);
}
public sealed class OwnershipCatalog {
    public HashSet<int> Models{get;}
    public Dictionary<(int Def,int Paint),int> Prices{get;}
    public Dictionary<int,int> ModelPrices{get;}
    public bool PurchasesEnabled{get;}
    public OwnershipCatalog(HashSet<int> models,Dictionary<(int,int),int> prices,bool purchasesEnabled) {
        Models=models;Prices=prices;PurchasesEnabled=purchasesEnabled;
        // A model is unlocked by any finish of it, so it is offered from the cheapest one.
        ModelPrices=prices.Where(p=>models.Contains(p.Key.Item1)).GroupBy(p=>p.Key.Item1).ToDictionary(g=>g.Key,g=>g.Min(p=>p.Value));
    }
}
public sealed record OwnershipReply(bool Enforced,IReadOnlyDictionary<ulong,HashSet<(int Def,int Paint)>> Players,OwnershipCatalog? Catalog);
/// <summary>Signed request/reply format of /api/ownership.php. Anything unexpected is rejected, never read as "not enforced".</summary>
public static class OwnershipProtocol {
    public const int MaxPlayers=64,MaxReplyBytes=1<<20;
    public static bool ValidSteamId(ulong id)=>id is >=76561190000000000UL and <=76561199999999999UL;
    public static string Request(string nonce,IReadOnlyList<ulong> ids,bool catalog)=>
        JsonSerializer.Serialize(new Dictionary<string,object>{["request"]=nonce,["steamids"]=ids.Select(i=>i.ToString(CultureInfo.InvariantCulture)).ToArray(),["catalog"]=catalog});
    public static OwnershipReply Parse(string body,string? stamp,string? signature,string key,string server,string nonce,IReadOnlyCollection<ulong> asked,bool catalog,long now) {
        if(stamp is null || stamp.Length!=10 || !stamp.All(char.IsAsciiDigit) || Math.Abs(now-long.Parse(stamp,CultureInfo.InvariantCulture))>300)throw new FormatException("Reply timestamp");
        if(signature is null || signature.Length!=64 || !CryptographicOperations.FixedTimeEquals(Encoding.ASCII.GetBytes(signature),Encoding.ASCII.GetBytes(EventSigner.SignResponse(key,server,stamp,body))))throw new FormatException("Reply signature");
        using var doc=JsonDocument.Parse(body);var root=doc.RootElement;
        if(root.ValueKind!=JsonValueKind.Object || Int(root,"v")!=1 || Str(root,"request")!=nonce || Str(root,"server")!=server)throw new FormatException("Reply identity");
        var enforced=Bool(root,"enforced");
        if(!enforced)return new(false,new Dictionary<ulong,HashSet<(int,int)>>(),null);
        if(!root.TryGetProperty("players",out var players) || players.ValueKind!=JsonValueKind.Object)throw new FormatException("Reply players");
        var map=new Dictionary<ulong,HashSet<(int,int)>>();
        foreach(var p in players.EnumerateObject()) {
            if(!ulong.TryParse(p.Name,NumberStyles.None,CultureInfo.InvariantCulture,out var id) || !asked.Contains(id) || p.Value.ValueKind!=JsonValueKind.Array)throw new FormatException("Reply player");
            map[id]=p.Value.EnumerateArray().Select(Pair).ToHashSet();
        }
        if(map.Count!=asked.Count)throw new FormatException("Reply misses a player");
        OwnershipCatalog? c=null;
        if(catalog) {
            if(!root.TryGetProperty("models",out var models) || models.ValueKind!=JsonValueKind.Array || !root.TryGetProperty("prices",out var prices) || prices.ValueKind!=JsonValueKind.Array)throw new FormatException("Reply catalog");
            var priceMap=new Dictionary<(int,int),int>();
            foreach(var row in prices.EnumerateArray()) {
                if(row.ValueKind!=JsonValueKind.Array || row.GetArrayLength()!=3 || !row[0].TryGetInt32(out var d) || !row[1].TryGetInt32(out var paint) || !row[2].TryGetInt32(out var cc) || cc<0)throw new FormatException("Reply price");
                priceMap[(d,paint)]=cc;
            }
            c=new(models.EnumerateArray().Select(m=>m.TryGetInt32(out var v)?v:throw new FormatException("Reply model")).ToHashSet(),priceMap,Bool(root,"purchases_enabled"));
        }
        return new(true,map,c);
    }
    private static (int,int) Pair(JsonElement e)=>e.ValueKind==JsonValueKind.Array && e.GetArrayLength()==2 && e[0].TryGetInt32(out var d) && e[1].TryGetInt32(out var p)?(d,p):throw new FormatException("Reply item");
    private static int Int(JsonElement e,string name)=>e.TryGetProperty(name,out var v) && v.TryGetInt32(out var i)?i:throw new FormatException(name);
    private static string Str(JsonElement e,string name)=>e.TryGetProperty(name,out var v) && v.ValueKind==JsonValueKind.String?v.GetString()!:throw new FormatException(name);
    private static bool Bool(JsonElement e,string name)=>e.TryGetProperty(name,out var v) && v.ValueKind is JsonValueKind.True or JsonValueKind.False?v.GetBoolean():throw new FormatException(name);
}
/// <summary>
/// Same rules as the website and the database guard. Fail-closed: without trustworthy data a paid item is Pending.
/// "Not enforced" is honoured only from a fresh explicit reply; owned items stay usable from the last good reply.
/// </summary>
public sealed class OwnershipCache(TimeSpan notEnforcedTtl,TimeSpan ownedTtl) {
    private sealed record Player(HashSet<(int,int)> Items,HashSet<int> Defs,DateTimeOffset At);
    private sealed record State(bool Enforced,DateTimeOffset At);
    private volatile State? state;
    private volatile OwnershipCatalog? catalog;
    private DateTimeOffset catalogAt;
    private readonly ConcurrentDictionary<ulong,Player> players=new();
    public DateTimeOffset? CatalogAt=>catalog is null?null:catalogAt;
    public (bool Enforced,DateTimeOffset At)? Enforcement=>state is {} s?(s.Enforced,s.At):null;
    public void Apply(OwnershipReply reply,DateTimeOffset now) {
        if(reply.Catalog is not null){catalogAt=now;catalog=reply.Catalog;}
        foreach(var (id,items) in reply.Players)players[id]=new(items,items.Select(i=>i.Item1).ToHashSet(),now);
        state=new(reply.Enforced,now);
    }
    public bool Has(ulong id,DateTimeOffset now,TimeSpan maxAge)=>players.TryGetValue(id,out var p) && now-p.At<maxAge;
    public void Forget(ulong id)=>players.TryRemove(id,out _);
    public OwnershipCheck Check(ulong id,int def,int paint,DateTimeOffset now) {
        var s=state;
        if(s is {Enforced:false} && now-s.At<=notEnforcedTtl)return OwnershipCheck.Free;
        if(def==0)return OwnershipCheck.Free;
        if(def<0)return new(Ownership.Locked,null);
        var c=catalog;
        if(c is null)return OwnershipCheck.Pending;
        var model=c.Models.Contains(def);
        if(paint==0 && !model)return OwnershipCheck.Free;
        if(!players.TryGetValue(id,out var p) || now-p.At>ownedTtl)return OwnershipCheck.Pending;
        if(paint==0)return p.Defs.Contains(def)?OwnershipCheck.Owned:new(Ownership.Locked,c.PurchasesEnabled && c.ModelPrices.TryGetValue(def,out var m)?m:null);
        return p.Items.Contains((def,paint))?OwnershipCheck.Owned:new(Ownership.Locked,c.PurchasesEnabled && c.Prices.TryGetValue((def,paint),out var price)?price:null);
    }
}
public sealed record OwnershipOptions {
    public TimeSpan NotEnforcedTtl{get;init;}=TimeSpan.FromMinutes(2);
    public TimeSpan OwnedTtl{get;init;}=TimeSpan.FromHours(6);
    public TimeSpan CatalogRefresh{get;init;}=TimeSpan.FromMinutes(10);
    public TimeSpan PlayerRefresh{get;init;}=TimeSpan.FromSeconds(5);
    public TimeSpan Poll{get;init;}=TimeSpan.FromMilliseconds(250);
}
/// <summary>Non-blocking ownership answers for the game thread; one background loop talks to the backend.</summary>
public sealed class OwnershipService : IAsyncDisposable {
    private readonly OwnershipCache cache;
    private readonly OwnershipOptions options;
    private readonly Uri endpoint;
    private readonly string server,key;
    private readonly Action<string> log;
    private readonly TimeProvider time;
    private readonly HttpClient client;
    private readonly ConcurrentDictionary<ulong,DateTimeOffset> wanted=new();
    private readonly ConcurrentDictionary<ulong,DateTimeOffset> asked=new();
    private readonly CancellationTokenSource stop=new();
    private readonly Task loop;
    public OwnershipService(Uri endpoint,string server,string key,Action<string> log,HttpMessageHandler? handler=null,TimeProvider? time=null,OwnershipOptions? options=null) {
        if(endpoint.Scheme!="https" || !string.IsNullOrEmpty(endpoint.UserInfo) || !string.IsNullOrEmpty(endpoint.Fragment))throw new ArgumentException("HTTPS endpoint required");
        if(key.Length<32 || !System.Text.RegularExpressions.Regex.IsMatch(server,"^[a-zA-Z0-9_-]{1,64}$"))throw new ArgumentException("Server/key configuration invalid");
        this.endpoint=endpoint;this.server=server;this.key=key;this.log=log;this.time=time??TimeProvider.System;this.options=options??new();
        cache=new(this.options.NotEnforcedTtl,this.options.OwnedTtl);
        client=new HttpClient(handler??new HttpClientHandler{AllowAutoRedirect=false}){Timeout=TimeSpan.FromSeconds(10)};
        loop=Task.Run(Loop);
    }
    public OwnershipCheck Check(ulong id,int def,int paint) {
        var r=OwnershipProtocol.ValidSteamId(id)?cache.Check(id,def,paint,time.GetUtcNow()):new(def==0?Ownership.Free:Ownership.Pending,null);
        if(r.Status==Ownership.Pending)Refresh(id);
        return r;
    }
    public void Refresh(ulong id) {
        if(!OwnershipProtocol.ValidSteamId(id))return;
        var now=time.GetUtcNow();
        if(asked.TryGetValue(id,out var last) && now-last<options.PlayerRefresh)return;
        wanted.TryAdd(id,now);
    }
    public void Forget(ulong id){wanted.TryRemove(id,out _);asked.TryRemove(id,out _);cache.Forget(id);}
    private bool CatalogDue(DateTimeOffset now) {
        if(cache.CatalogAt is not {} at || now-at>=options.CatalogRefresh)return true;
        // Keep an explicit "not enforced" fresh; when it cannot be renewed it expires and paid items lock again.
        return cache.Enforcement is {Enforced:false} e && now-e.At>=options.NotEnforcedTtl/2;
    }
    private async Task Loop() {
        int failures=0;
        try {
            while(!stop.IsCancellationRequested) {
                await Task.Delay(options.Poll,stop.Token);
                var now=time.GetUtcNow();
                var ids=wanted.Keys.Take(OwnershipProtocol.MaxPlayers).ToArray();
                var withCatalog=CatalogDue(now);
                if(ids.Length==0 && !withCatalog)continue;
                try {
                    await Fetch(ids,withCatalog,now);
                    foreach(var id in ids){wanted.TryRemove(id,out _);asked[id]=now;}
                    if(failures>0)log("Ownership data available again");
                    failures=0;
                } catch(Exception ex) when(ex is HttpRequestException or FormatException or JsonException or TaskCanceledException or InvalidOperationException or IOException) {
                    if(stop.IsCancellationRequested)return;
                    if(failures++==0)log("Ownership data unavailable; paid items stay locked unless already known as owned ("+ex.GetType().Name+")");
                    await Task.Delay(TimeSpan.FromMilliseconds(Math.Min(30000,options.Poll.TotalMilliseconds*Math.Pow(2,Math.Min(failures,7)))),stop.Token);
                }
            }
        } catch(OperationCanceledException) when(stop.IsCancellationRequested){}
    }
    private async Task Fetch(ulong[] ids,bool withCatalog,DateTimeOffset now) {
        var nonce=Convert.ToHexStringLower(RandomNumberGenerator.GetBytes(16));
        var body=OwnershipProtocol.Request(nonce,ids,withCatalog);
        var stamp=now.ToUnixTimeSeconds().ToString(CultureInfo.InvariantCulture);
        using var request=new HttpRequestMessage(HttpMethod.Post,endpoint){Content=new StringContent(body,Encoding.UTF8,"application/json")};
        request.Headers.Add("X-CloudHub-Server",server);request.Headers.Add("X-CloudHub-Timestamp",stamp);request.Headers.Add("X-CloudHub-Signature",EventSigner.Sign(key,server,stamp,body));
        using var response=await client.SendAsync(request,HttpCompletionOption.ResponseHeadersRead,stop.Token);
        if(response.StatusCode!=System.Net.HttpStatusCode.OK)throw new HttpRequestException("Ownership view unavailable");
        await using var stream=await response.Content.ReadAsStreamAsync(stop.Token);
        var buffer=new byte[OwnershipProtocol.MaxReplyBytes+1];int length=0,read;
        while(length<buffer.Length && (read=await stream.ReadAsync(buffer.AsMemory(length),stop.Token))>0)length+=read;
        if(length>OwnershipProtocol.MaxReplyBytes)throw new HttpRequestException("Oversized ownership reply");
        string? Header(string name)=>response.Headers.TryGetValues(name,out var v)?v.SingleOrDefault():null;
        var reply=OwnershipProtocol.Parse(Encoding.UTF8.GetString(buffer,0,length),Header("X-CloudHub-Timestamp"),Header("X-CloudHub-Signature"),key,server,nonce,ids,withCatalog,time.GetUtcNow().ToUnixTimeSeconds());
        cache.Apply(reply,now);
    }
    public async ValueTask DisposeAsync(){await stop.CancelAsync();try{await loop;}catch(OperationCanceledException){}client.Dispose();stop.Dispose();}
}
