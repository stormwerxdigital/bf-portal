/*
 * An activity's skills come from the activity's own track.
 *
 * A Track 1 activity offers Track 1 skills and nothing else, and a Tracks 2
 * and 3 activity the reverse. Changing the track select moves the lock at
 * once, without a save. A skill from the other track that was already on the
 * activity is kept, because a save does not drop it, but it is marked so a
 * person decides what to do with it.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-skill-lock.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 820, height: 900 } });
const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });
await page.goto('file://' + page_path);
await page.waitForTimeout(250);

/* The row already there holds 103, a Tracks 2 and 3 skill on a Track 1 activity. */
const old = '.bftd-skillpull .bftd-actpick:has(option[value="103"]:checked)';
check(await page.locator(old).count() === 1, 'the attached skill from the other track is still on the activity');
check(await page.locator(old + '.is-offtrack').count() === 1, 'and is marked as being from the other track');
const note = await page.locator(old + ' .bftd-actpick-off').textContent().catch(() => '');
check(/Tracks? 2 (&|and) 3/.test(note) && /Track 1/.test(note), 'saying which track it is in and which it should be, got ' + JSON.stringify(note));

check(await page.locator('.bftd-skillpull .bftd-actpick-b').first().isVisible() === false,
  'the track buttons are gone, because the track is not a choice here');

/* Add a row and search. Only Track 1 skills come back. */
await page.click('.bftd-skillpull .bftd-row-add');
await page.waitForTimeout(100);
const fresh = page.locator('.bftd-skillpull .bftd-actpick:not(.is-chosen)').last();
const offered = async term => {
  await fresh.locator('.bftd-actpick-q').fill('');
  await fresh.locator('.bftd-actpick-q').fill(term);
  await page.waitForTimeout(80);
  return fresh.locator('.bftd-actpick-r li[data-value]').evaluateAll(l => l.map(x => x.getAttribute('data-value')));
};
const s1 = await offered('s');
check(s1.length > 0 && s1.every(v => v === '100' || v === '101'), 'a Track 1 activity offers only Track 1 skills, got ' + s1);
const plural = await offered('plural');
check(plural.length === 0, 'and a search for a Tracks 2 and 3 skill finds nothing, got ' + plural);
await fresh.locator('.bftd-actpick-q').fill('');
await fresh.locator('.bftd-actpick-q').dispatchEvent('input');
await page.waitForTimeout(80);
const sel = await fresh.locator('.bftd-actpick-s option').evaluateAll(o => o.filter(x => x.value).map(x => x.value));
check(sel.length === 2 && sel.every(v => v === '100' || v === '101'), 'the plain select underneath offers the same, got ' + sel);

/* Switch the activity to Tracks 2 and 3, without saving. */
await page.selectOption('#bftd_activity_track', 't23');
await page.waitForTimeout(100);
const s2 = await offered('s');
check(s2.length > 0 && s2.every(v => v === '102' || v === '103'), 'switching the track switches what is offered, got ' + s2);
check(await page.locator(old + '.is-offtrack').count() === 0, 'and the attached skill is no longer marked, because it now matches');

check(errors.length === 0, 'no script errors ' + errors.join(' | '));
await browser.close();
process.exit(fail ? 1 : 0);
