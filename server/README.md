# CloudHub Core 0.2.0

Disabled by default. Requires CounterStrikeSharp API **1.0.376** and .NET 10.

```bash
cd server
dotnet restore CloudHub.Plugin --locked-mode
dotnet restore CloudHub.Core.Tests --locked-mode
dotnet build CloudHub.Plugin -c Release --no-restore
dotnet run --project CloudHub.Core.Tests -c Release --no-restore -- ../tests/protocol-vector.json
```

This test runner exits nonzero on any failed assertion. It needs no game server or production credentials. HTTP in outbox tests uses an injected in-memory handler; real HTTPS/game integration remains a staging check.

Only deploy CloudHub.Plugin.dll, CloudHub.Core.dll and CloudHub.Plugin.deps.json into a dedicated staging plugin directory. Preserve `outbox/`. Do not ship dependency DLLs over CounterStrikeSharp's installation. Configuration is generated with Enabled=false. HMAC keys come from the named environment variable, never JSON.

Full protocol, antifarm rules, failure boundaries, separate ingestion pool and rollout/rollback: [CORE_STAGE2.pl.md](../docs/CORE_STAGE2.pl.md). Match System integration is an unconnected adapter contract; no whole-match rewards are calculated.

## Ownership for in-game menus (Stage 6B)

`CloudHub.Ownership.Contracts` (MIT) defines `ICloudHubOwnership`. This plugin publishes it as the CounterStrikeSharp capability `cloudhub:ownership` when `OwnershipEnabled=true` (default `false`). Only the CloudHub fork of WeaponPaints consumes it.

- Deploy `CloudHub.Ownership.Contracts.dll` once into CounterStrikeSharp's shared directory (`addons/counterstrikesharp/shared/CloudHub.Ownership.Contracts/`), never next to either plugin. The build does not copy it. Confirm the layout on the test server.
- Answers come from memory on the game thread. One background loop queries the signed `/api/ownership.php`, at most 64 players per request.
- Fail-closed: without a valid signed reply every paid item is `Pending` (not allowed). "Not enforced" only counts from a reply at most 2 minutes old. Owned items stay usable from the last good reply for up to 6 hours.
