using CloudHub.Core;
using CloudHub.Ownership;
namespace CloudHub.Plugin;
/// <summary>Exposes the Core service as "cloudhub:ownership" for the CloudHub fork of WeaponPaints. Pending after Close.</summary>
internal sealed class OwnershipCapability(OwnershipService service) : ICloudHubOwnership {
    private volatile OwnershipService? service=service;
    public OwnershipDecision Check(ulong steamId,int weaponDefindex,int paintId) {
        if(service is not {} s)return new(weaponDefindex==0?OwnershipStatus.Free:OwnershipStatus.Pending,null);
        var r=s.Check(steamId,weaponDefindex,paintId);
        return new(r.Status switch{Core.Ownership.Free=>OwnershipStatus.Free,Core.Ownership.Owned=>OwnershipStatus.Owned,Core.Ownership.Locked=>OwnershipStatus.Locked,_=>OwnershipStatus.Pending},r.PriceCc);
    }
    public void Refresh(ulong steamId)=>service?.Refresh(steamId);
    public void Close()=>service=null;
}
