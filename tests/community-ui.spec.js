import { test, expect } from '@playwright/test';
import fs from 'node:fs';
async function authenticate(context,index=0) {
  const user=JSON.parse(fs.readFileSync('test-results/sessions.json'))[index];
  await context.addCookies([{name:'wp_session',value:user.id,domain:'127.0.0.1',path:'/',httpOnly:true,sameSite:'Lax'}]);
}
async function accept(page) { const button=page.getByRole('button',{name:'Akceptuję',exact:true}); await expect(button).toBeVisible(); await button.click(); }
test('Community UI: anonymous ranking, search, empty state, profile and mobile',async({page})=>{
  const errors=[]; page.on('pageerror',e=>errors.push(e.message));
  await page.goto('/ranking'); await accept(page);
  await expect(page.getByRole('heading',{name:'Ranking graczy.'})).toBeVisible();
  await expect(page.locator('.ch-community-table tbody tr').first()).toBeVisible();
  await page.getByLabel('Nick lub SteamID64').fill('Cloud Player'); await page.getByRole('button',{name:'Szukaj',exact:true}).click();
  await expect(page.locator('.ch-community-table tbody tr')).toHaveCount(1);
  await page.getByRole('link',{name:'Cloud Player',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Cloud Player',exact:true})).toBeVisible();
  await expect(page.locator('.ch-profile-hero .ch-badge--owner')).toBeVisible();
  await expect(page.locator('.ch-profile-hero .ch-badge--top')).toBeVisible();
  await expect(page.getByRole('button',{name:'Zapisz profil'})).toHaveCount(0);
  await page.screenshot({path:'test-results/cloudhub-stage3-profile.png',fullPage:true});
  await page.getByRole('link',{name:'Ranking',exact:true}).click();
  await page.getByLabel('Nick lub SteamID64').fill('no-player-for-this-search');await page.getByRole('button',{name:'Szukaj',exact:true}).click();
  await expect(page.getByRole('heading',{name:'Brak danych',exact:true})).toBeVisible();
  await page.getByLabel('Nick lub SteamID64').fill('');await page.getByRole('button',{name:'Szukaj',exact:true}).click();
  await page.setViewportSize({width:390,height:844});
  await expect(page.locator('.ch-community-table tbody tr').first()).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  await page.screenshot({path:'test-results/cloudhub-stage3-ranking-mobile.png',fullPage:true});
  expect(errors).toEqual([]);
});
test('Community UI: own profile edit, private wallet and OWNER operations',async({page,context})=>{
  await authenticate(context);await page.goto('/players/76561198000000001');await accept(page);
  await page.getByLabel('Opis profilu').fill('<script>plain text</script>');await page.getByRole('button',{name:'Zapisz profil'}).click();
  await expect(page.getByText('Profil zapisany.',{exact:true})).toBeVisible();
  await expect(page.locator('.ch-profile-bio')).toHaveText('<script>plain text</script>');
  await page.getByRole('link',{name:'Administracja',exact:true}).click();
  await expect(page.locator('.ch-community-table tbody tr').first()).toBeVisible();
  await page.getByLabel('Znajdź gracza').fill('Other Player');await page.getByRole('button',{name:'Szukaj',exact:true}).click();
  await page.getByRole('button',{name:'Korekta CC'}).click();
  await page.getByLabel('Kwota (minus oznacza odjęcie)').fill('3');await page.getByLabel('Powód operacji').fill('Browser test');
  await page.getByRole('button',{name:'Zapisz korektę CC'}).click();
  await expect(page.locator('.ch-adjustment').getByRole('status')).toContainText('Zapisano operację');
  await expect(page.locator('.ch-audit-entry').first()).toContainText('Browser test');
  await page.getByLabel('Kwota (minus oznacza odjęcie)').fill('-3');await page.getByLabel('Powód operacji').fill('Browser test reversal');await page.getByRole('button',{name:'Zapisz korektę CC'}).click();
  await expect(page.locator('.ch-adjustment').getByRole('status')).toContainText('Zapisano operację');
  await page.getByRole('link',{name:'Portfel',exact:true}).click();
  await expect(page.locator('.ch-wallet-balance')).toBeVisible();
  await page.screenshot({path:'test-results/cloudhub-stage3-wallet.png',fullPage:true});
});
test('Community UI: ordinary player has no administration tab',async({page,context})=>{
  await authenticate(context,1);await page.goto('/wallet');await accept(page);
  await expect(page.locator('.ch-wallet-balance')).toBeVisible();
  await expect(page.getByRole('link',{name:'Administracja',exact:true})).toHaveCount(0);
  await page.goto('/admin');await expect(page.getByRole('alert').first()).toContainText('OWNER required');
});
test('OWNER UI: lost response and reload retry one durable CC operation',async({page,context})=>{
  await authenticate(context); await page.goto('/admin'); await accept(page);
  await page.getByLabel('Znajdź gracza').fill('Other Player');await page.getByRole('button',{name:'Szukaj',exact:true}).click();
  await page.getByRole('button',{name:'Korekta CC'}).click();
  await page.getByLabel('Kwota (minus oznacza odjęcie)').fill('2');await page.getByLabel('Powód operacji').fill('Lost response retry test');
  await page.route('**/api/admin.php?action=adjust',async route=>{ await route.fetch(); await route.abort('failed'); },{times:1});
  await page.getByRole('button',{name:'Zapisz korektę CC'}).click();
  await expect(page.getByRole('button',{name:'Ponów tę samą operację'})).toBeVisible();
  await expect(page.getByRole('button',{name:'Korekta CC'})).toBeDisabled();
  await page.reload();
  await page.getByRole('button',{name:'Ponów tę samą operację'}).click();
  await expect(page.locator('.ch-adjustment').getByRole('status')).toContainText('Zapisano operację');
  await expect(page.locator('.ch-audit-entry').filter({hasText:'Lost response retry test'})).toHaveCount(1);
  await page.getByLabel('Kwota (minus oznacza odjęcie)').fill('-2');await page.getByLabel('Powód operacji').fill('Lost response reversal');
  await page.getByRole('button',{name:'Zapisz korektę CC'}).click();await expect(page.locator('.ch-adjustment').getByRole('status')).toContainText('Zapisano operację');
});
