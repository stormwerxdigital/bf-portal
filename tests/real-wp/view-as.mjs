/*
 * View as client, in a real WordPress (1.150). See README.md here for the site.
 *   BFTD_PORTAL='/?page_id=5' node tests/real-wp/view-as.mjs [screenshot.png]
 * Needs users admin, senior, manager, tutor and client (client linked to a
 * published student), all with the password in BFTD_PASS.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const B=process.env.BFTD_WP||'http://127.0.0.1:8099'; const PORTAL=process.env.BFTD_PORTAL||'/portal/'; const PW=process.env.BFTD_PASS||'pw12345678!'; const SH=process.argv[2];
let fail=0; const check=(ok,m)=>{console.log((ok?'  ok ':'FAIL')+'  '+m); if(!ok) fail++;};
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
async function as(user){ const c=await b.newContext({viewport:{width:1300,height:900}}); const p=await c.newPage(); p.on('dialog',d=>{p.lastDialog=d.message(); d.accept();});
  await p.goto(B+'/wp-login.php'); await p.fill('#user_login',user); await p.fill('#user_pass',PW); await Promise.all([p.waitForNavigation(),p.click('#wp-submit')]); return p; }
const text = async p => (await p.locator('.bf-portal').first().innerText()).replace(/\s+/g,' ').trim();

// The client's own portal
const cl = await as('client'); await cl.goto(B+PORTAL); const mine = await text(cl);
check(mine.length > 200, 'the client sees their portal ('+mine.length+' chars)');

// Admin: Users list
const ad = await as('admin'); await ad.goto(B+'/wp-admin/users.php');
const link = await ad.locator('tr:has-text("client@example.com") a:has-text("View as client")').first().getAttribute('href').catch(()=>null);
check(!!link, 'the client row on Users has "View as client"');
check(await ad.locator('tr:has-text("tutor@example.com") a:has-text("View as client")').count() === 0, 'a tutor row does not');
await ad.goto(link); await ad.waitForTimeout(800);
const theirs = await text(ad);
check(theirs === mine, 'the view is exactly what the client sees' + (theirs===mine?'':' (differs: '+theirs.length+' vs '+mine.length+')'));
check(/Viewing as .* \(client\)\. Read only/.test(await ad.locator('.bftd-viewas-bar').innerText()), 'with a bar saying whose view it is and that it is read only');
check(await ad.locator('#wpadminbar').count() === 0, 'and without the staff toolbar, as the client has none');
if (SH) await ad.screenshot({ path: SH, fullPage: false });

// Moving about keeps the view
const nav = ad.locator('.bf-portal a[href*="screen="]').first();
if (await nav.count()) { const href = await nav.getAttribute('href'); check(href.includes('bftd_view_as='), 'portal links keep the view'); await ad.goto(href); check(await ad.locator('.bftd-viewas-bar').count() === 1, 'and the next page is still the client view'); }

// Read only: the page's own requests are stopped, and a forged one is refused
const r = await ad.evaluate(async () => (await (await fetch(BFTD.ajax_url, { method: 'POST', body: new URLSearchParams({ action: 'bftd_post_message', nonce: BFTD.nonce, post_id: 1, section: 'x', body: 'hi' }) })).json()));
check(r && r.success === false && /read-only/i.test(ad.lastDialog || ''), 'posting from the page is stopped, and says why');
const forged = await ad.evaluate(async () => { const raw = window.fetch.toString().includes('read-only') ? null : null; const res = await XMLHttpRequest ? new Promise(ok => { const x = new XMLHttpRequest(); x.open('POST', BFTD.ajax_url); x.setRequestHeader('Content-Type','application/x-www-form-urlencoded'); x.onload = () => ok(x.status + ' ' + x.responseText.slice(0,60)); x.send('action=bftd_mark_read&thread=1&nonce=' + BFTD.nonce); }) : ''; return res; });
check(/^(403|400)|^200 -1|"success":false/.test(forged), 'and a request sent around the page script is refused by WordPress: ' + forged);

// Tampering
await ad.goto(B+PORTAL+(PORTAL.includes('?')?'&':'?')+'bftd_view_as=4&bftd_view_as_nonce=x'); check(await ad.locator('.bftd-viewas-bar').count() === 0, 'a made-up link does nothing');
await ad.goto(B+'/wp-admin/users.php'); 
const tlink = link.replace(/bftd_view_as=\d+/, 'bftd_view_as=4');
await ad.goto(tlink); check(await ad.locator('.bftd-viewas-bar').count() === 0, 'and a staff account can never be viewed this way');

// Who gets it
for (const [u, want] of [['senior', true], ['manager', false], ['tutor', false]]) {
  const p = await as(u); await p.goto(B+'/wp-admin/users.php');
  const n = await p.locator('a:has-text("View as client")').count();
  check(want ? n > 0 : n === 0, `${u}: ${want ? 'has' : 'does not have'} the option`);
  if (want) { const h = await p.locator('tr:has-text("client@example.com") a:has-text("View as client")').first().getAttribute('href'); await p.goto(h); await p.waitForTimeout(500); check((await text(p)) === mine, `${u}: sees exactly the client view`); }
  else { await p.goto(link); check(await p.locator('.bftd-viewas-bar').count() === 0, `${u}: an admin's link does not work for them`); }
}
await b.close(); console.log(fail ? 'FAIL' : 'PASS'); process.exit(fail ? 1 : 0);
