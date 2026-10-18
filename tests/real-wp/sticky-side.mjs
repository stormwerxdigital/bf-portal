import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const B='http://127.0.0.1:8099';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'}); const p=await b.newPage({viewport:{width:1400,height:900}});
await p.goto(B+'/wp-login.php'); await p.fill('#user_login','admin'); await p.fill('#user_pass','pw12345678!'); await Promise.all([p.waitForNavigation(),p.click('#wp-submit')]);
await p.goto(B+'/wp-admin/post.php?post='+process.argv[2]+'&action=edit'); await p.waitForTimeout(2500);
const btn = await p.$('#publishing-action input[type=submit], #publish');
for (const y of [0, 1500, 4000, 8000, 99999]) {
  await p.evaluate(y => window.scrollTo(0, y), y); await p.waitForTimeout(300);
  const r = await btn.boundingBox(); const pv = await p.$eval('#bftd-preview, .bftd-preview-btn, a[href*="preview"]', e => { const r=e.getBoundingClientRect(); return Math.round(r.top); }).catch(()=>'?');
  console.log('scroll', await p.evaluate(()=>Math.round(scrollY)), 'publish top', Math.round(r.y), 'visible', r.y > 0 && r.y + r.height < 900, 'preview', pv);
}
await p.evaluate(() => window.scrollTo(0, 5000)); await p.waitForTimeout(300); await p.screenshot({ path: process.argv[3] });
await p.setViewportSize({width:800,height:900}); await p.waitForTimeout(300);
console.log('narrow top css:', await p.$eval('#postbox-container-1', e => getComputedStyle(e).position + ' ' + e.style.top));
await b.close();
