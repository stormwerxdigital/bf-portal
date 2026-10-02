/*
 * One person, not a list.
 *
 * The client picker is the caregiver picker with an attribute on it. Nothing
 * in the markup makes a second pick impossible — the select underneath is
 * still a multiple — so if the script does not replace, the field quietly
 * becomes a second caregiver list under a different label and the save takes
 * whichever name happens to be first. It looks like nothing going wrong.
 *
 * The only way to see it is to click twice.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-client-pick.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await browser.newPage({ viewport: { width: 900, height: 900 } });
const errors = [];
p.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

/* What the form would post, which is the only thing that reaches the save. */
const posted = (sel) => p.evaluate(s =>
  [...document.querySelectorAll(s + ' .bftd-assign-select option:checked')].map(o => o.value), sel);

const chips = (sel) => p.evaluate(s =>
  [...document.querySelectorAll(s + ' .bftd-chip')].map(c => c.querySelector('.bftd-chip-name').textContent.trim()), sel);

check((await posted('#one')).join() === '20', 'the client field opens holding one person');

async function pick(sel, term) {
  await p.fill(sel + ' .bftd-assign-in', term);
  await p.waitForTimeout(120);
  const hit = await p.$(sel + ' .bftd-assign-hit');
  check(!!hit, `searching "${term}" in ${sel} offers somebody`);
  if (hit) await hit.click();
  await p.waitForTimeout(120);
}

await pick('#one', 'Dana');
check((await posted('#one')).join() === '21', 'picking a second replaces the first, got ' + (await posted('#one')).join());
check((await chips('#one')).length === 1, 'and one chip is drawn, got ' + JSON.stringify(await chips('#one')));

/* The same widget, uncapped, still adds. A cap applied to everything would
   have broken the caregivers instead, and that failure looks identical from
   the server. */
await pick('#many', 'Dana');
check((await posted('#many')).sort().join() === '20,21',
  'the caregiver list still adds rather than replacing, got ' + (await posted('#many')).join());

/* Removing the one leaves the field empty rather than stuck. */
await p.click('#one .bftd-chip-x');
await p.waitForTimeout(120);
check((await posted('#one')).length === 0, 'the client can be cleared');
check(await p.evaluate(() => !!document.querySelector('#one .bftd-chip-none')), 'and says nobody is named');

await pick('#one', 'ravi@example.test');
check((await posted('#one')).join() === '22', 'and searching by email finds them again');

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
