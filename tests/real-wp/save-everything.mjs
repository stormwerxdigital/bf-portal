/*
 * Every field, every save, in a real WordPress (1.148).
 *
 *   node tests/real-wp/save-everything.mjs <post_type> [user]
 *
 * Fills every field on a new record's edit screen, then checks what survives:
 *   1 the silent draft autosave, after a reload
 *   2 Publish
 *   3 an autosave on the published record, after a reload: the screen must
 *     open on the draft, with "Draft saved, update to take live"
 *   4 Update
 * Prints every field that came back different. Not run by tests/run.sh: it
 * needs a WordPress at BFTD_WP (default http://127.0.0.1:8099). See README.md
 * here for setting one up. Expected differences are listed there too.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const B = process.env.BFTD_WP || 'http://127.0.0.1:8099';
const STUDENT = process.env.BFTD_STUDENT || '';
const ACTIVITY = process.env.BFTD_ACTIVITY || '';
const SKILL = process.env.BFTD_SKILL || '';
const SHOTS = process.env.BFTD_SHOTS || '/tmp';
const [type, user = 'admin', tag = 'A'] = process.argv.slice(2);
const pw = process.env.BFTD_PASS || 'pw12345678!';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1400, height: 1000 } });
page.on('dialog', d => d.accept());
const errs = []; page.on('pageerror', e => errs.push(String(e.stack || e).slice(0, 600)));
page.on('response', async r => { if (r.url().includes('admin-ajax') && r.request().postData()?.includes('bftd_autosave')) { let t=''; try { t = (await r.text()).slice(0,300); } catch(e){} console.log('  [autosave', r.status(), t.replace(/\s+/g,' '), ']'); } });
await page.goto(B + '/wp-login.php');
await page.fill('#user_login', user); await page.fill('#user_pass', pw);
await Promise.all([page.waitForNavigation(), page.click('#wp-submit')]);

async function ready() {
  await page.waitForLoadState('load');
  await page.waitForFunction(() => !window.tinymce || (tinymce.editors || []).every(e => e.initialized), null, { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(800);
}
async function collect() {
  return page.evaluate(() => {
    if (window.tinymce) try { tinymce.triggerSave(); } catch (e) {}
    const out = {};
    for (const el of document.querySelectorAll('form#post [name]')) {
      const n = el.name;
      if (!/^(bftd|post_title)/.test(n) || /nonce|bftd_sent/.test(n)) continue;
      if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) continue;
      if (el.type === 'hidden' && /^bftd_visible/.test(n)) continue;
      const v = el.tagName === 'SELECT' && el.multiple ? [...el.selectedOptions].map(o => o.value).join(',') : el.value;
      (out[n] = out[n] || []).push(String(v).replace(/\s+/g, ' ').trim());
    }
    return out;
  });
}
async function fill(round) {
  return page.evaluate(({ round, student }) => {
    const touched = [];
    const mark = (n) => `${round}-${n.replace(/[^a-z0-9]+/gi, '').slice(-24)}`;
    for (const el of document.querySelectorAll('form#post [name]')) {
      const n = el.name;
      if (!/^bftd/.test(n) || /nonce|bftd_sent|bftd_visible/.test(n)) continue;
      // A row's activity or skill is picked into a hidden field; give it the seeded one.
      if (ACTIVITY && el.type === 'hidden' && /\[activities\]\[\d+\]\[id\]$/.test(n)) { el.value = ACTIVITY; el.dispatchEvent(new Event('change', { bubbles: true })); touched.push(n); continue; }
      if (SKILL && el.type === 'hidden' && /\[skills\]\[\d+\]\[id\]$/.test(n)) { el.value = SKILL; el.dispatchEvent(new Event('change', { bubbles: true })); touched.push(n); continue; }
      if (el.type === 'hidden' || el.type === 'file' || el.disabled || el.readOnly) continue;
      if (el.closest('.wp-editor-wrap') && window.tinymce && tinymce.get(el.id)) continue;
      if (el.tagName === 'SELECT') {
        const opts = [...el.options].filter(o => o.value !== '');
        if (!opts.length) continue;
        if (/student/.test(n) && student) { const o = opts.find(o => o.value == student); if (o) { el.value = o.value; } }
        else if (/\[session\]\[status\]/.test(n)) el.value = opts[0].value;
        else if (n === 'bftd_activity_track' || n === 'bftd_skill_track') { const o = opts.find(o => o.value === 't23'); if (o) el.value = 't23'; }   // the seeded skill's track   // a held session, so no cancellation is required to publish
        else el.value = opts[(round === 'A' ? opts.length - 1 : 0)].value;
      } else if (el.type === 'checkbox' || el.type === 'radio') {
        el.checked = round === 'A' ? true : !el.checked;
      } else if (el.type === 'number') {
        // Inside the field's own limits, or the browser refuses to submit.
        const step = (el.step && el.step !== 'any') ? Number(el.step) : 1;
        const lo = el.min !== '' ? Number(el.min) : 0;
        let v = lo + step * ((round === 'A' ? 7 : 9) + touched.length % 5);
        if (el.max !== '' && v > Number(el.max)) v = Number(el.max) - (round === 'A' ? 0 : step);
        el.value = String(v);
      }
      else if (el.type === 'date') el.value = round === 'A' ? '2026-09-01' : '2026-09-02';
      else if (el.type === 'time') el.value = round === 'A' ? '10:00' : '11:00';
      else el.value = mark(n);
      el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true }));
      touched.push(n);
    }
    if (window.tinymce) for (const ed of tinymce.editors) {
      const ta = document.getElementById(ed.id); if (!ta || !/^bftd/.test(ta.name || '')) continue;
      ed.setContent('<p>' + mark(ta.name) + '</p>'); ed.fire('change'); touched.push(ta.name);
    }
    const t = document.querySelector('#title'); if (t) { t.value = 'E2E ' + round; t.dispatchEvent(new Event('input', { bubbles: true })); }
    return touched.length;
  }, { round, student: STUDENT });
}
async function waitSaved() {
  const r = await page.waitForResponse(r => r.url().includes('admin-ajax') && (r.request().postData() || '').includes('bftd_autosave'), { timeout: 45000 }).catch(() => null);
  await page.waitForTimeout(400);
  const st = await page.locator('.bftd-autosave-status').first().textContent().catch(() => '');
  return (r ? 'response ' + r.status() : 'NO AUTOSAVE') + ', status text: [' + (st || '').trim() + ']';
}
function diff(a, b, label) {
  const lost = [];
  for (const k of Object.keys(a)) {
    const x = (a[k] || []).join(' | '), y = (b[k] || []).join(' | ');
    if (x !== y) lost.push(`${k}: wanted [${x.slice(0, 70)}] got [${y.slice(0, 70)}]`);
  }
  console.log(`${label}: ${lost.length ? lost.length + ' differ' : 'all ' + Object.keys(a).length + ' fields kept'}`);
  for (const l of lost) console.log('   ' + l);
  return lost.length;
}

await page.goto(B + '/wp-admin/post-new.php?post_type=' + type); await ready();
const id = await page.inputValue('#post_ID');
console.log(`${type} as ${user}, post ${id}, filled`, await fill('A'));
const wantA = await collect();
if (process.env.SAVEDRAFT) { await Promise.all([page.waitForNavigation({ timeout: 30000 }), page.click('#save-post')]); console.log('saved with Save Draft'); }
else console.log('autosave status:', await waitSaved());
await page.goto(`${B}/wp-admin/post.php?post=${id}&action=edit`); await ready();
console.log('after reload, status bars:', await page.locator('.bftd-restore, .notice').allInnerTexts().then(t => t.map(x => x.slice(0, 90)).join(' || ')));
diff(wantA, await collect(), '1 draft autosave, reload');

// Publish
await page.evaluate(() => { const s = document.querySelector('.bftd-autosave-status'); if (s) s.textContent = ''; });
try { await Promise.all([page.waitForNavigation({ timeout: 30000 }), page.click('#publish')]); } catch (e) {
  await page.screenshot({ path: SHOTS + '/publish-stuck-' + type + '.png', fullPage: false });
  console.log('PUBLISH DID NOT NAVIGATE. visible notices:', await page.evaluate(() => [...document.querySelectorAll('.notice,.error,.bftd-hold,[role=alert],#publish')].filter(e => e.offsetParent).map(e => (e.id||e.className)+': '+e.textContent.trim().slice(0,150)).join(' || ')));
  process.exit(0);
}
await ready();
console.log('status now:', await page.evaluate(() => document.querySelector('#post-status-display')?.textContent.trim()), '|', await page.evaluate(() => [...document.querySelectorAll('.notice')].filter(e => e.offsetParent).map(e => e.textContent.trim().replace(/\s+/g,' ').slice(0,160)).join(' || ')));
diff(wantA, await collect(), '2 publish');

// Autosave on published
console.log('filled', await fill('B'));
const wantB = await collect();
console.log('autosave status (published):', await waitSaved());
await page.goto(`${B}/wp-admin/post.php?post=${id}&action=edit`); await ready();
console.log('after reload, bars:', await page.locator('.bftd-restore').allInnerTexts().then(t => t.map(x => x.slice(0, 90)).join(' || ')));
diff(wantB, await collect(), '3 published autosave, reload (should show the draft)');

// Update
await page.goto(`${B}/wp-admin/post.php?post=${id}&action=edit`); await ready();
await fill('B'); const wantU = await collect();
try { await Promise.all([page.waitForNavigation({ timeout: 30000 }), page.click('#publish')]); } catch (e) {
  await page.screenshot({ path: SHOTS + '/update-stuck-' + type + '.png' });
  console.log('UPDATE DID NOT NAVIGATE:', await page.evaluate(() => [...document.querySelectorAll('.notice,.error,[role=alert],.bftd-calc-warn,.bftd-hold')].filter(e => e.offsetParent).map(e => e.textContent.trim().slice(0,160)).join(' || ')));
  process.exit(0);
}
await ready();
diff(wantU, await collect(), '4 update');
if (errs.length) console.log('JS errors:', errs.slice(0, 5));
await browser.close();
