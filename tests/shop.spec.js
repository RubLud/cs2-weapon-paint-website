import { test, expect } from '@playwright/test'
import fs from 'node:fs'
const base='http://127.0.0.1:5175'
const sessions=()=>JSON.parse(fs.readFileSync('test-results/sessions.json'))
async function client(playwright,index) {
  const cookies=index===undefined?[]:[{name:'wp_session',value:sessions()[index].id,domain:'127.0.0.1',path:'/',expires:-1,httpOnly:true,secure:false,sameSite:'Lax'}]
  return playwright.request.newContext({baseURL:base,storageState:{cookies,origins:[]}})
}
async function post(ctx,endpoint,form) {
  const {csrf_token}=await (await ctx.get('/api/session.php')).json()
  return ctx.post('/api/'+endpoint,{form,headers:{'X-CSRF-Token':csrf_token,Origin:base}})
}
const balance=async ctx=>(await (await ctx.get('/api/cloudhub.php')).json()).account.balance
async function credit(owner,steamid,amount) {
  const r=await post(owner,'admin.php?action=adjust',{steamid,amount:String(amount),reason:'Shop test fixture',request_id:crypto.randomUUID()})
  expect(r.status()).toBe(200)
}
test('Shop API: auth, CSRF, server price, replay, IDOR and free personalization',async({playwright})=>{
  const anon=await client(playwright), player=await client(playwright,5), other=await client(playwright,4), owner=await client(playwright,0)
  const pid=sessions()[5].steamid, oid=sessions()[4].steamid
  const catalog=await (await anon.get('/api/shop.php?action=catalog&kind=weapon_skin&sort=price_asc')).json()
  expect(catalog.total).toBeGreaterThan(0)
  expect(catalog.items.every(i=>i.owned===false)).toBe(true)
  const item=catalog.items[0]
  expect((await anon.get('/api/shop.php?action=inventory')).status()).toBe(401)
  expect((await anon.post('/api/shop.php?action=buy',{form:{product_id:String(item.id),request_id:crypto.randomUUID()}})).status()).toBe(401)
  expect((await player.get('/api/shop.php?action=buy&product_id='+item.id)).status()).toBe(405)
  expect((await player.post('/api/shop.php?action=buy',{form:{product_id:String(item.id),request_id:crypto.randomUUID()}})).status()).toBe(403)
  const csrf=(await (await player.get('/api/session.php')).json()).csrf_token
  expect((await player.post('/api/shop.php?action=buy',{form:{product_id:String(item.id),request_id:crypto.randomUUID()},headers:{'X-CSRF-Token':csrf,Origin:'https://evil.example'}})).status()).toBe(403)
  expect((await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:crypto.randomUUID()})).status()).toBe(409)
  await credit(owner,pid,item.price_cc+50)
  expect((await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:crypto.randomUUID(),price:'1'})).status()).toBe(422)
  expect((await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:crypto.randomUUID(),steamid:oid})).status()).toBe(422)
  expect((await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:crypto.randomUUID(),expected_price:'1'})).status()).toBe(409)
  const request=crypto.randomUUID()
  const bought=await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:request,expected_price:String(item.price_cc)})
  expect(bought.status()).toBe(200)
  expect((await bought.json()).balance).toBe('50')
  const replay=await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:request})
  expect((await replay.json()).status).toBe('duplicate')
  expect(await balance(player)).toBe('50')
  expect((await post(player,'shop.php?action=buy',{product_id:String(item.id),request_id:crypto.randomUUID()})).status()).toBe(409)
  const inventory=await (await player.get('/api/shop.php?action=inventory&steamid='+oid)).json()
  expect(inventory.items.map(i=>i.id)).toEqual([item.id])
  expect((await (await other.get('/api/shop.php?action=inventory&steamid='+pid)).json()).items).toEqual([])
  expect((await (await player.get('/api/shop.php?action=purchases')).json()).items[0].price_cc).toBe(item.price_cc)
  const ledger=(await (await player.get('/api/cloudhub.php?action=history')).json()).transactions
  expect(ledger[0]).toMatchObject({source:'shop',amount:String(-item.price_cc),balance_after:'50'})
  // Configurator index: own ownership only, never another player's.
  const mine=(await (await player.get('/api/shop.php?action=prices')).json()).items
  expect(mine).toContainEqual([item.weapon_defindex,item.paint_id,item.price_cc,true])
  expect((await (await anon.get('/api/shop.php?action=prices')).json()).items.some(r=>r[3])).toBe(false)
  expect((await (await other.get('/api/shop.php?action=prices&steamid='+pid)).json()).items.some(r=>r[3])).toBe(false)
  // Personalization of the owned skin costs 0 CC.
  const sticker=JSON.parse(fs.readFileSync('frontend/public/data/stickers_en.json')).find(s=>Number(s.id)>0)
  const save=await post(player,'skins.php',{action:'save',team:'T',weapon_defindex:String(item.weapon_defindex),paint:String(item.paint_id),wear:'0.01',seed:'661',nametag:'Free',stattrak:'1',stattrak_count:'0',keychainId:'0',offsetX:'0',offsetY:'0',offsetZ:'0',keychainSeed:'0',weapon_sticker_0:`${sticker.id};0;0;0;0;1;0`})
  expect(save.status()).toBe(200)
  expect(await balance(player)).toBe('50')
  expect((await (await anon.get('/api/shop.php?action=catalog')).json()).items.some(i=>i.owned)).toBe(false)
  // OWNER-only management.
  expect((await player.get('/api/admin.php?action=shop_products')).status()).toBe(403)
  expect((await post(player,'admin.php?action=shop_product',{product_id:String(item.id),price_cc:'1',is_active:'1',reason:'x',request_id:crypto.randomUUID()})).status()).toBe(403)
  const products=await (await owner.get('/api/admin.php?action=shop_products&search='+encodeURIComponent(item.name))).json()
  expect(products.items.find(p=>p.id===item.id).owners).toBe(1)
  const stats=await (await owner.get('/api/admin.php?action=shop_stats')).json()
  expect(Number(stats.purchases)).toBeGreaterThan(0)
  await credit(owner,pid,-50)
  await anon.dispose();await player.dispose();await other.dispose();await owner.dispose()
})
test('Shop UI: catalog, in-app purchase confirmation, inventory and OWNER pricing',async({page,context,playwright})=>{
  const s=sessions(), owner=await client(playwright,0), pid=s[4].steamid
  const catalog=await (await owner.get('/api/shop.php?action=catalog&kind=glove')).json()
  const item=catalog.items[0]
  await credit(owner,pid,item.price_cc)
  await context.addCookies([{name:'wp_session',value:s[4].id,domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'}])
  const errors=[];page.on('pageerror',e=>errors.push(e.message))
  page.on('dialog',dialog=>{errors.push('native '+dialog.type());dialog.dismiss()})
  await page.goto('/shop?kind=glove')
  await expect(page.getByRole('heading',{name:'Sklep.'})).toBeVisible()
  await expect(page.getByRole('link',{name:'Ekwipunek',exact:true}).first()).toBeVisible()
  const card=page.locator('.ch-shop-card',{hasText:item.name})
  await card.getByRole('button',{name:'Kup'}).click()
  await page.getByRole('alertdialog').getByRole('button',{name:'Kup',exact:true}).click()
  await expect(page.getByRole('status').filter({hasText:'Kupiono'})).toBeVisible()
  await expect(card.getByText('Posiadasz')).toBeVisible()
  await page.goto('/inventory')
  await expect(page.locator('.ch-shop-card',{hasText:item.name}).getByRole('link',{name:'Personalizuj za 0 CC'})).toBeVisible()
  await expect(page.getByText('−'+new Intl.NumberFormat('pl-PL').format(item.price_cc)+' CC')).toBeVisible()
  await page.goto('/wallet')
  await expect(page.getByText('Zakup w sklepie').first()).toBeVisible()
  await page.setViewportSize({width:390,height:844})
  await page.goto('/shop')
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true)
  await page.setViewportSize({width:1280,height:900})
  // Every API response refreshes wp_session, so leave the page first: a late response must not restore the previous player.
  await page.goto('about:blank')
  await context.clearCookies()
  await context.addCookies([{name:'wp_session',value:s[0].id,domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'}])
  await page.goto('/admin')
  const shop=page.locator('section',{has:page.getByRole('heading',{name:'Sklep i ekonomia'})})
  await shop.getByLabel('Produkt').fill(item.name);await shop.getByRole('button',{name:'Szukaj produktu'}).click()
  await shop.locator('tr',{hasText:item.name}).getByRole('button',{name:'Edytuj'}).click()
  await shop.getByLabel('Cena (CC)').fill(String(item.price_cc+1));await shop.getByLabel('Powód zmiany').fill('UI price test')
  await shop.getByRole('button',{name:'Zapisz'}).click()
  await expect(shop.getByText(new Intl.NumberFormat('pl-PL').format(item.price_cc+1)+' CC')).toBeVisible()
  expect(errors).toEqual([])
  await owner.dispose()
})
test('Configurator: CC price and owned badge without changing selection or saving',async({page,context,playwright})=>{
  const s=sessions(), owner=await client(playwright,0), buyer=await client(playwright,3)
  const ak=(await (await owner.get('/api/shop.php?action=catalog&weapon=7')).json()).items[0]
  expect(ak).toBeTruthy()
  const finish=ak.name.split('|')[1].trim()
  await context.addCookies([{name:'wp_session',value:s[3].id,domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'}])
  const errors=[];page.on('pageerror',e=>errors.push(e.message))
  const open=async()=>{
    await page.goto('/t/rifle')
    const consent=page.getByRole('button',{name:'Akceptuję',exact:true})
    if (await consent.isVisible().catch(()=>false)) await consent.click()
    await page.getByRole('searchbox',{name:'Szukaj broni lub skina…'}).fill('AK-47')
    await page.locator('.weapon-card').click()
    await page.locator('.wc-skins__search').fill(finish)
    return page.locator('.skin-button',{hasText:finish}).first()
  }
  let skin=await open()
  await expect(skin.locator('.skin-shop-badge')).toHaveText(new Intl.NumberFormat('pl-PL').format(ak.price_cc)+' CC')
  await skin.click()
  await expect(skin).toHaveClass(/selected/)
  await credit(owner,s[3].steamid,ak.price_cc)
  expect((await post(buyer,'shop.php?action=buy',{product_id:String(ak.id),request_id:crypto.randomUUID()})).status()).toBe(200)
  skin=await open()
  await expect(skin.locator('.skin-shop-badge--owned')).toHaveText('Posiadasz')
  expect(errors).toEqual([])
  await owner.dispose();await buyer.dispose()
})
