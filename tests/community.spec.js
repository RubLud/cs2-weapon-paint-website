import { test, expect } from '@playwright/test';
import fs from 'node:fs';
const base='http://127.0.0.1:5175';
export async function communityClient(playwright,index) {
  const cookies=index===undefined?[]:[{name:'wp_session',value:JSON.parse(fs.readFileSync('test-results/sessions.json'))[index].id,domain:'127.0.0.1',path:'/',expires:-1,httpOnly:true,secure:false,sameSite:'Lax'}];
  return playwright.request.newContext({baseURL:base,storageState:{cookies,origins:[]}});
}
export async function communityPost(ctx,endpoint,form) {
  const {csrf_token}=await (await ctx.get('/api/session.php')).json();
  return ctx.post('/api/'+endpoint,{form,headers:{'X-CSRF-Token':csrf_token,Origin:base}});
}
test('Community API: public badges, profile privacy, edit ownership and CSRF',async({playwright})=>{
  const anon=await communityClient(playwright), player=await communityClient(playwright,1);
  const url='/api/community.php?action=profile&steamid=76561198000000002';
  expect((await anon.get(url)).status()).toBe(200);
  expect((await anon.post('/api/community.php?action=edit')).status()).toBe(401);
  expect((await player.post('/api/community.php?action=edit')).status()).toBe(403);
  const form={bio:'Test <b>plain</b>',profile_visibility:'private',activity_visibility:'private'};
  expect((await communityPost(player,'community.php?action=edit',{...form,steamid:'76561198000000001'})).status()).toBe(422);
  expect((await communityPost(player,'community.php?action=edit',{...form,rp:'9999'})).status()).toBe(422);
  expect((await communityPost(player,'community.php?action=edit',form)).status()).toBe(200);
  expect((await anon.get(url)).status()).toBe(404);
  const profile=(await (await player.get(url)).json()).profile;
  expect(profile.bio).toBe(form.bio); expect(profile.editable).toBe(true);
  expect(JSON.stringify(profile)).not.toContain('balance');
  expect((await anon.get('/api/community.php?sort[]=rp')).status()).toBe(422);
  expect((await anon.get('/api/community.php?limit=100000')).status()).toBe(422);
  expect((await communityPost(player,'community.php?action=edit',{...form,bio:'',profile_visibility:'public'})).status()).toBe(200);
  await anon.dispose(); await player.dispose();
});
test('OWNER API: authorization, CSRF, idempotency, balance and private ledger',async({playwright})=>{
  const anon=await communityClient(playwright),player=await communityClient(playwright,1),owner=await communityClient(playwright,0);
  expect((await anon.get('/api/admin.php')).status()).toBe(401);
  for(const action of ['players','audit','config']) expect((await player.get('/api/admin.php?action='+action)).status()).toBe(403);
  const form={steamid:'76561198000000002',amount:'7',reason:'API test',request_id:'20000000-0000-4000-8000-000000000001'};
  expect((await communityPost(player,'admin.php?action=adjust',form)).status()).toBe(403);
  expect((await owner.post('/api/admin.php?action=adjust',{form})).status()).toBe(403);
  const token=(await (await owner.get('/api/session.php')).json()).csrf_token;
  expect((await owner.post('/api/admin.php?action=adjust',{form,headers:{'X-CSRF-Token':token,Origin:'https://evil.example'}})).status()).toBe(403);
  expect((await owner.get('/api/admin.php?action=adjust')).status()).toBe(405);
  expect((await (await communityPost(owner,'admin.php?action=adjust',form)).json()).balance).toBe('7');
  expect((await (await communityPost(owner,'admin.php?action=adjust',form)).json()).status).toBe('duplicate');
  expect((await communityPost(owner,'admin.php?action=adjust',{...form,amount:'8'})).status()).toBe(409);
  expect((await communityPost(owner,'admin.php?action=adjust',{...form,amount:'-8',request_id:'20000000-0000-4000-8000-000000000002'})).status()).toBe(409);
  const ledger=await (await player.get('/api/cloudhub.php?action=history&steamid=76561198000000001')).json();
  expect(ledger.transactions[0].amount).toBe('7');
  expect((await (await owner.get('/api/admin.php?action=audit')).json()).entries[0].actor_steamid).toBe('76561198000000001');
  expect((await communityPost(owner,'admin.php?action=adjust',{...form,amount:'-7',request_id:'20000000-0000-4000-8000-000000000003'})).status()).toBe(200);
  await anon.dispose(); await player.dispose(); await owner.dispose();
});
