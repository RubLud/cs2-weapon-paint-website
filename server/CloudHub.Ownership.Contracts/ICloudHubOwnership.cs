// SPDX-License-Identifier: MIT
// Shared through CounterStrikeSharp's shared assemblies: both plugins must load this one copy of the DLL.
// Keep it stable; an incompatible change needs a new assembly name and capability name.
namespace CloudHub.Ownership;
public static class CloudHubOwnershipCapability {
    public const string Name = "cloudhub:ownership";
}
public enum OwnershipStatus {
    /// <summary>Default item, or enforcement explicitly off according to a fresh backend reply.</summary>
    Free = 0,
    Owned = 1,
    /// <summary>Paid item the player does not own. PriceCc is null when it cannot be bought right now.</summary>
    Locked = 2,
    /// <summary>No trustworthy data yet (backend unreachable, player not loaded). Must be treated as not allowed.</summary>
    Pending = 3,
}
public readonly record struct OwnershipDecision(OwnershipStatus Status, int? PriceCc) {
    public bool Allowed => Status is OwnershipStatus.Free or OwnershipStatus.Owned;
}
/// <summary>Called on the game thread: never blocks, answers from memory and refreshes in the background.</summary>
public interface ICloudHubOwnership {
    /// <summary>Weapon finish, or a knife/glove model when paintId is 0.</summary>
    OwnershipDecision Check(ulong steamId, int weaponDefindex, int paintId);
    /// <summary>Asks for fresh data, for example when a menu opens. Rate limited by the provider.</summary>
    void Refresh(ulong steamId);
}
