import { test, expect } from '@playwright/test'
import fs from 'node:fs'
// Port 8084 serves the same database and sessions with CLOUDHUB_OWNERSHIP_ENFORCED; the app on 5175 keeps it off.
const base='http://127.0.0.1:5175', enforced='http://127.0.0.1:8084'
const sessions=()=>JSON.parse(fs.readFileSync('test-results/sessions.json'))
async function client(playwright,index,baseURL=base) {
  const cookies=[{name:'wp_session',value:sessions()[index].id,domain:'127.0.0.1',path:'/',expires:-1,httpOnly:true,secure:false,sameSite:'Lax'}]
  return playwright.request.newContext({baseURL,storageState:{cookies,origins:[]}})
}
const api=ctx=>ctx._base===enforced?'/':'/api/'
async function post(ctx,endpoint,form) {
  const {csrf_token}=await (await ctx.get(api(ctx)+'session.php')).json()
  return ctx.post(api(ctx)+endpoint,{form,headers:{'X-CSRF-Token':csrf_token,Origin:base}})
}
async function enforcedClient(playwright,index) { const ctx=await client(playwright,index,enforced); ctx._base=enforced; return ctx }
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
