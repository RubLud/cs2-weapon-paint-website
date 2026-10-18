import { test, expect } from '@playwright/test'
import fs from 'node:fs'
// Same database and sessions: 8084 has CLOUDHUB_OWNERSHIP_ENFORCED, 8085 the flag with the shop off; the app on 5175 keeps it off.
const base='http://127.0.0.1:5175', enforced='http://127.0.0.1:8084', shopOff='http://127.0.0.1:8085'
const sessions=()=>JSON.parse(fs.readFileSync('test-results/sessions.json'))
async function client(playwright,index,baseURL=base) {
  const cookies=[{name:'wp_session',value:sessions()[index].id,domain:'127.0.0.1',path:'/',expires:-1,httpOnly:true,secure:false,sameSite:'Lax'}]
  return playwright.request.newContext({baseURL,storageState:{cookies,origins:[]}})
}
const api=ctx=>ctx._direct?'/':'/api/'
async function post(ctx,endpoint,form) {
  const {csrf_token}=await (await ctx.get(api(ctx)+'session.php')).json()
  return ctx.post(api(ctx)+endpoint,{form,headers:{'X-CSRF-Token':csrf_token,Origin:base}})
}
async function enforcedClient(playwright,index,url=enforced) { const ctx=await client(playwright,index,url); ctx._direct=true; return ctx }
const save=(ctx,paint,extra={})=>post(ctx,'skins.php',{action:'save',team:'T',weapon_defindex:'7',paint:String(paint),wear:'0.1',seed:'1',...extra})
const skins=async(ctx,team='T')=>(await post(ctx,'skins.php',{action:'getall',team})).json()

test('Ownership API: enforced saves, free personalization, knives, gloves and presets',async({playwright})=>{
  const s=sessions(), on=await enforcedClient(playwright,1), off=await client(playwright,1), owner=await client(playwright,0)
  expect((await (await on.get('/cloudhub.php')).json()).features.ownership_enforcement).toBe(true)
  expect((await (await off.get('/api/cloudhub.php')).json()).features.ownership_enforcement).toBe(false)
  expect((await (await on.get('/shop.php?action=prices')).json()).enforced).toBe(true)
  const catalog=kind=>off.get('/api/shop.php?action=catalog&kind='+kind).then(r=>r.json())
  const ak=(await (await off.get('/api/shop.php?action=catalog&weapon=7')).json()).items[0]
  const glove=(await catalog('glove')).items[0], knife=(await catalog('knife')).items.find(p=>p.paint_id>0)
  expect(ak.owned).toBe(false); expect(glove.owned).toBe(false)

  // Unowned finish: refused before anything is stored.
  const refused=await post(on,'skins.php',{action:'save',team:'T',weapon_defindex:'7',paint:String(ak.paint_id),wear:'0.1',seed:'1'})
  expect(refused.status()).toBe(403)
  const body=await refused.json()
  expect(body.code).toBe('not_owned'); expect(body.items).toEqual([{weapon_defindex:7,paint_id:ak.paint_id}])
  expect((await skins(on)).some(r=>Number(r.weapon_defindex)===7 && Number(r.weapon_paint_id)===ak.paint_id)).toBe(false)
  // Default finish and default knife stay free; an unowned knife model is refused.
  expect((await post(on,'skins.php',{action:'save',team:'T',weapon_defindex:'7',paint:'0',wear:'0.2',seed:'5'})).status()).toBe(200)
  expect((await post(on,'knife.php',{action:'set',team:'T',knife:'weapon_knife'})).status()).toBe(200)
  const knifeName=(await (await off.get('/weapons.json')).json()).find(w=>w.cs2_id===knife.weapon_defindex).name
  expect((await post(on,'knife.php',{action:'set',team:'T',knife:knifeName})).status()).toBe(403)
  expect((await post(on,'skins.php',{action:'gloves_save',team:'CT',weapon_defindex:String(glove.weapon_defindex),paint:String(glove.paint_id),wear:'0.1',seed:'1'})).status()).toBe(403)

  // After a purchase the gloves save, and personalization of them is free.
  expect((await post(owner,'admin.php?action=adjust',{steamid:s[1].steamid,amount:String(glove.price_cc),reason:'Ownership test fixture',request_id:crypto.randomUUID()})).status()).toBe(200)
  expect((await post(off,'shop.php?action=buy',{product_id:String(glove.id),request_id:crypto.randomUUID()})).status()).toBe(200)
  for (const [wear,seed] of [['0.1','1'],['0.66','777']])
    expect((await post(on,'skins.php',{action:'gloves_save',team:'CT',weapon_defindex:String(glove.weapon_defindex),paint:String(glove.paint_id),wear,seed})).status()).toBe(200)
  const saved=(await skins(on,'CT')).find(r=>Number(r.weapon_defindex)===glove.weapon_defindex)
  expect([Number(saved.weapon_paint_id),Number(saved.weapon_seed)]).toEqual([glove.paint_id,777])

  // Without enforcement the same save works as before; a preset holding it cannot be applied under enforcement.
  expect((await post(off,'skins.php',{action:'save',team:'T',weapon_defindex:'7',paint:String(ak.paint_id),wear:'0.1',seed:'1'})).status()).toBe(200)
  const preset=await (await post(off,'loadouts.php',{action:'save',name:'Ownership preset',scope:'team',team:'T',is_public:'0'})).json()
  expect(preset.id).toBeTruthy()
  expect((await post(on,'skins.php',{action:'reset',team:'T',weapon_defindex:'7'})).status()).toBe(200)
  const apply=await post(on,'loadouts.php',{action:'apply',id:String(preset.id)})
  expect(apply.status()).toBe(403)
  expect((await apply.json()).items).toContainEqual({weapon_defindex:7,paint_id:ak.paint_id})
  expect((await skins(on)).some(r=>Number(r.weapon_defindex)===7)).toBe(false)
  expect((await post(off,'loadouts.php',{action:'delete',id:String(preset.id)})).status()).toBe(200)
  await on.dispose();await off.dispose();await owner.dispose()
})

test('Ownership UI: configurator marks unowned finishes and shows the refused save',async({page,context})=>{
  const s=sessions()
  await context.addCookies([{name:'wp_session',value:s[1].id,domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'}])
  // Send the app's API calls to the enforced backend.
  await page.route('**/api/**',async route=>{
    const url=route.request().url().replace(base+'/api/',enforced+'/')
    await route.fulfill({response:await route.fetch({url})})
  })
  const errors=[];page.on('pageerror',e=>errors.push(e.message))
  const ak=(await (await page.request.get(enforced+'/shop.php?action=catalog&weapon=7')).json()).items[0]
  const finish=ak.name.split('|')[1].trim()
  await page.goto('/t/rifle')
  const consent=page.getByRole('button',{name:'Akceptuję',exact:true})
  if (await consent.isVisible().catch(()=>false)) await consent.click()
  await page.getByRole('searchbox',{name:'Szukaj broni lub skina…'}).fill('AK-47')
  await page.locator('.weapon-card').click()
  await page.locator('.wc-skins__search').fill(finish)
  const skin=page.locator('.skin-button',{hasText:finish}).first()
  await expect(skin.locator('.skin-shop-badge--locked')).toHaveText('Kup: '+new Intl.NumberFormat('pl-PL').format(ak.price_cc)+' CC')
  await skin.click()
  await expect(skin).toHaveClass(/selected/)
  await page.locator('.wc-btn--primary').click()
  await expect(page.getByRole('alert')).toContainText('Nie posiadasz tego przedmiotu')
  await expect(page.locator('.wc-btn--primary')).toBeEnabled()
  expect(errors).toEqual([])
})

test('Ownership security: no bypass through direct API calls',async({playwright})=>{
  const s=sessions(), on=await enforcedClient(playwright,2), owner=await client(playwright,0), off=await client(playwright,2)
  const anon=await playwright.request.newContext({baseURL:enforced})
  const ak=(await (await off.get('/api/shop.php?action=catalog&weapon=7')).json()).items[0]
  // Identity comes only from the session: another player's SteamID in the body changes nothing.
  for (const extra of [{steamid:s[0].steamid},{steamid64:s[0].steamid,owned:'1',price_cc:'0'}]) {
    const r=await save(on,ak.paint_id,extra)
    expect(r.status()).toBe(403); expect((await r.json()).code).toBe('not_owned')
  }
  expect((await anon.post('/skins.php',{form:{action:'save',team:'T',weapon_defindex:'7',paint:String(ak.paint_id)}})).status()).toBe(401)
  expect((await on.post('/skins.php',{form:{action:'save',team:'T',weapon_defindex:'7',paint:String(ak.paint_id)},headers:{Origin:base}})).status()).toBe(403)
  expect((await on.get('/skins.php?action=save&team=T&weapon_defindex=7&paint='+ak.paint_id)).status()).toBe(405)
  // Unknown combinations and malformed values are refused, never treated as free.
  for (const paint of ['99999','100001','-1','abc']) expect([403,422]).toContain((await save(on,paint)).status())
  expect((await post(on,'knife.php',{action:'set',team:'T',knife:'weapon_knife_not_real'})).status()).toBe(422)
  // A public preset from another player is a way in only if its items are owned.
  expect((await save(off,ak.paint_id)).status()).toBe(200)
  const preset=await (await post(off,'loadouts.php',{action:'save',name:'Shared bait',scope:'team',team:'T',is_public:'1'})).json()
  const thief=await enforcedClient(playwright,3), thiefOff=await client(playwright,3)
  const copy=await (await post(thiefOff,'loadouts.php',{action:'copy',id:String(preset.id)})).json()
  expect(copy.id).toBeTruthy()
  expect((await (await thiefOff.get('/api/shop.php?action=prices')).json()).items.some(([d,p,,o])=>d===7&&p===ak.paint_id&&o)).toBe(false)
  expect((await post(thief,'loadouts.php',{action:'apply',id:String(copy.id)})).status()).toBe(403)
  expect((await (await post(thief,'skins.php',{action:'getall',team:'T'})).json()).some(r=>Number(r.weapon_defindex)===7 && Number(r.weapon_paint_id)===ak.paint_id)).toBe(false)
  // Buying grants it once: a repeated purchase is refused and the wallet is not charged twice.
  expect((await post(owner,'admin.php?action=adjust',{steamid:s[2].steamid,amount:String(ak.price_cc*2),reason:'Ownership test fixture',request_id:crypto.randomUUID()})).status()).toBe(200)
  const wallet=async()=>Number((await (await off.get('/api/cloudhub.php')).json()).account.balance)
  const start=await wallet()
  expect((await post(off,'shop.php?action=buy',{product_id:String(ak.id),request_id:crypto.randomUUID()})).status()).toBe(200)
  expect((await post(off,'shop.php?action=buy',{product_id:String(ak.id),request_id:crypto.randomUUID()})).status()).toBe(409)
  expect(await wallet()).toBe(start-ak.price_cc)
  expect((await save(on,ak.paint_id)).status()).toBe(200)
  for (const ctx of [post(off,'loadouts.php',{action:'delete',id:String(preset.id)}),post(thiefOff,'loadouts.php',{action:'delete',id:String(copy.id)})]) expect((await ctx).status()).toBe(200)
  for (const ctx of [on,owner,off,anon,thief,thiefOff]) await ctx.dispose()
})

test('Ownership with the shop disabled: WeaponPaints works exactly as before',async({playwright})=>{
  const ctx=await enforcedClient(playwright,4,shopOff), app=await client(playwright,4)
  expect((await ctx.get('/shop.php?action=prices')).status()).toBe(404)
  const ak=(await (await app.get('/api/shop.php?action=catalog&weapon=7')).json()).items[0]
  expect(ak.owned).toBe(false)
  expect((await save(ctx,ak.paint_id)).status()).toBe(200)
  expect((await post(ctx,'knife.php',{action:'set',team:'T',knife:'weapon_knife_karambit'})).status()).toBe(200)
  expect((await (await post(ctx,'skins.php',{action:'getall',team:'T'})).json()).some(r=>Number(r.weapon_defindex)===7 && Number(r.weapon_paint_id)===ak.paint_id)).toBe(true)
  expect((await post(ctx,'skins.php',{action:'reset',team:'T',weapon_defindex:'7'})).status()).toBe(200)
  expect((await post(ctx,'knife.php',{action:'set',team:'T',knife:'weapon_knife'})).status()).toBe(200)
  await ctx.dispose();await app.dispose()
})