import { test, expect } from '@playwright/test'
import fs from 'node:fs'
import crypto from 'node:crypto'
// Server-to-server ownership view for the in-game menus. 8084: enforcement and the API on; 8085: API on, shop off; 5175/8082: API off.
const enforced='http://127.0.0.1:8084/ownership.php', shopOff='http://127.0.0.1:8085/ownership.php'
const key=()=>fs.readFileSync('test-results/ingest-key.txt','utf8')
const sha=s=>crypto.createHash('sha256').update(s).digest('hex')
const mac=(prefix,server,stamp,body,k=key())=>crypto.createHmac('sha256',k).update(`${prefix}\n${server}\n${stamp}\n${sha(body)}`).digest('hex')
function signed(body,{stamp=String(Math.floor(Date.now()/1000)),k=key(),server='test'}={}) {
  return {'content-type':'application/json','x-cloudhub-server':server,'x-cloudhub-timestamp':stamp,'x-cloudhub-signature':mac('v1',server,stamp,body,k)}
}
const nonce=()=>crypto.randomBytes(16).toString('hex')
const ask=(request,url,payload)=>{const body=JSON.stringify(payload);return request.post(url,{data:body,headers:signed(body)})}

test('Ownership server API: off by default, server authentication and request validation',async({request})=>{
  const body=JSON.stringify({request:nonce(),steamids:[],catalog:false})
  expect((await request.post('/api/ownership.php',{data:body,headers:signed(body)})).status()).toBe(404)
  expect((await request.get(enforced)).status()).toBe(405)
  expect((await request.post(enforced,{data:body,headers:{'content-type':'application/json'}})).status()).toBe(401)
  expect((await request.post(enforced,{data:body,headers:signed(body,{k:'x'.repeat(64)})})).status()).toBe(401)
  expect((await request.post(enforced,{data:body,headers:signed(body,{server:'other'})})).status()).toBe(401)
  expect((await request.post(enforced,{data:body,headers:signed(body,{stamp:String(Math.floor(Date.now()/1000)-600)})})).status()).toBe(401)
  expect((await request.post(enforced,{data:body,headers:{...signed(body),'content-type':'text/plain'}})).status()).toBe(415)
  const id='76561198000000901'
  for (const bad of [{steamids:[id],catalog:false},{request:'short',steamids:[id],catalog:false},{request:nonce(),steamids:['123'],catalog:false},
    {request:nonce(),steamids:[id,id],catalog:false},{request:nonce(),steamids:[Number(id)],catalog:false},{request:nonce(),steamids:[id],catalog:'1'},
    {request:nonce(),steamids:Array.from({length:65},(_,i)=>'7656119800000'+String(1000+i)),catalog:false}])
    expect((await ask(request,enforced,bad)).status()).toBe(422)
})

test('Ownership server API: signed explicit replies, every player answered, shop off is explicit',async({request})=>{
  const request_id=nonce(), ids=['76561198000000901','76561198000000902']
  const r=await ask(request,enforced,{request:request_id,steamids:ids,catalog:true})
  expect(r.status()).toBe(200)
  const text=await r.text(), h=r.headers()
  expect(h['x-cloudhub-signature']).toBe(mac('r1','test',h['x-cloudhub-timestamp'],text))
  // A reply never verifies as a request.
  expect(h['x-cloudhub-signature']).not.toBe(mac('v1','test',h['x-cloudhub-timestamp'],text))
  expect((await request.post(enforced,{data:text,headers:{'content-type':'application/json','x-cloudhub-server':'test','x-cloudhub-timestamp':h['x-cloudhub-timestamp'],'x-cloudhub-signature':h['x-cloudhub-signature']}})).status()).toBe(401)
  const reply=JSON.parse(text)
  expect(reply).toMatchObject({v:1,request:request_id,server:'test',enforced:true,purchases_enabled:true})
  expect(Object.keys(reply.players)).toEqual(ids)
  expect(Object.values(reply.players).every(items=>Array.isArray(items) && items.length===0)).toBe(true)
  expect(reply.models).toContain(507)
  expect(reply.models).not.toContain(7)
  expect(reply.prices.length).toBeGreaterThan(0)
  expect(reply.prices.every(p=>p.length===3 && p.every(Number.isInteger))).toBe(true)
  const lean=await (await ask(request,enforced,{request:nonce(),steamids:[],catalog:false})).json()
  expect(lean.players).toEqual({}); expect(lean.prices).toBeUndefined()
  const off=await ask(request,shopOff,{request:nonce(),steamids:ids,catalog:true})
  expect(off.status()).toBe(200)
  const offReply=await off.json()
  expect(offReply.enforced).toBe(false); expect(offReply.players).toEqual({}); expect(offReply.prices).toBeUndefined()
})
