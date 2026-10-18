// Builds frontend/public/data/shop_rarity.json from a pinned ByMykel/CSGO-API snapshot (MIT, parsed CS2 game data).
// Usage: node scripts/sync-shop-rarity.mjs [--commit <sha> --sha256 <hex>]
// The pinned commit and checksum make the output reproducible; bump both deliberately after a CS2 update.
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";

const args = process.argv.slice(2);
const arg = (name, fallback) => {
  const i = args.indexOf(name);
  return i >= 0 ? args[i + 1] : fallback;
};
const commit = arg("--commit", "72f932098c2fb1ddf647abcf22adee90ccffa088");
const expected = arg("--sha256", "7aeb9582c5f3308be78c78d2fd3681e3c469c67c0aeeeb7a9e54adb5c3be32d7");
if (!/^[a-f0-9]{40}$/.test(commit) || !/^[a-f0-9]{64}$/.test(expected)) throw new Error("Invalid --commit or --sha256");
const url = `https://raw.githubusercontent.com/ByMykel/CSGO-API/${commit}/public/api/en/skins.json`;
const response = await fetch(url);
if (!response.ok) throw new Error(`Download failed: HTTP ${response.status}`);
const body = Buffer.from(await response.arrayBuffer());
const actual = crypto.createHash("sha256").update(body).digest("hex");
if (actual !== expected) throw new Error(`Checksum mismatch: ${actual}`);

const rarities = {
  rarity_common_weapon: "consumer", rarity_uncommon_weapon: "industrial", rarity_rare_weapon: "mil_spec",
  rarity_mythical_weapon: "restricted", rarity_legendary_weapon: "classified", rarity_ancient_weapon: "covert",
  rarity_ancient: "extraordinary", rarity_contraband_weapon: "contraband", rarity_contraband: "contraband",
};
const items = [];
for (const skin of JSON.parse(body.toString("utf8"))) {
  const defindex = Number(skin.weapon?.weapon_id);
  const paint = Number(skin.paint_index ?? 0);
  const rarity = rarities[skin.rarity?.id];
  if (!Number.isInteger(defindex) || !Number.isInteger(paint) || !rarity) {
    console.warn(`Skipped (unrecognized rarity or ids): ${skin.name}`);
    continue;
  }
  items.push({
    weapon_defindex: defindex,
    paint_id: paint,
    rarity,
    phase: typeof skin.phase === "string" ? skin.phase : null,
    finish: typeof skin.pattern?.name === "string" ? skin.pattern.name : null,
    stattrak: skin.stattrak === true,
    souvenir: skin.souvenir === true,
  });
}
items.sort((a, b) => a.weapon_defindex - b.weapon_defindex || a.paint_id - b.paint_id);
const out = {
  source: `https://github.com/ByMykel/CSGO-API/blob/${commit}/public/api/en/skins.json`,
  license: "MIT, Copyright (c) 2023 ByMykel — see docs/THIRD_PARTY_NOTICES.md",
  sha256: actual,
  items,
};
const target = path.join(process.cwd(), "frontend/public/data/shop_rarity.json");
fs.writeFileSync(target, JSON.stringify(out) + "\n");
console.log(`Wrote ${items.length} rarity entries to ${path.relative(process.cwd(), target)}`);
