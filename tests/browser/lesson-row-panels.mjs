/*
 * Nothing hangs off an activity row until it has an activity.
 *
 * Notes and work samples belong to an activity. Offered before one is chosen,
 * they give a tutor somewhere to type that the save throws away, because a row
 * with no activity is not a record of anything.
 *
 * The state a row starts in is rendered by PHP and checked there. What can only
 * be checked here is the change: choosing an activity has to bring the panels
 * out, and clearing it has to put them away again.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-lesson-row.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 900, height: 800 } });
const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });

await page.goto('file://' + page_path);
await page.waitForTimeout(250);

/* The page carries several independent row sets, so every query here is
   scoped to one of them by id. Reading "the last row on the page" picked up
   another set's row and reported a bug that was not there; scoping by
   :first-of-type only moved the problem, because the next set added to the
   page matched that too. */
/* A row that already has notes opens with its editor built, full toolbar and
   Add Media, rather than as a plain box of raw HTML waiting for a click. */
const built = await page.evaluate(() => (window.__ed || []).map(e => ({ id: e.id, s: e.s })));
const filledId = await page.evaluate(() => (document.querySelector('#filled .bftd-rowpanel.is-open .bftd-rowpanel-a') || {}).id);
const mine = built.find(e => e.id === filledId);
check(!!filledId && !!mine, 'notes already written open in the full editor, got ' + JSON.stringify(built.map(e => e.id)));
check(mine && /formatselect/.test(mine.s.tinymce.toolbar1) && /forecolor/.test(mine.s.tinymce.toolbar2), 'with the full toolbar');
check(mine && true === mine.s.mediaButtons, 'and Add Media');
check(!built.some(e => /empty/.test(e.id)) && built.length === 1, 'while closed panels wait to be opened, got ' + built.length);

const SET = '#empty .bftd-rows';

const state = () => page.evaluate(sel => {
  const r = document.querySelector(sel + ' .bftd-stackrow');
  return {
    blank: r.classList.contains('is-blank'),
    toggles: [...r.querySelectorAll('.bftd-rowpanel-t')].filter(t => t.offsetParent !== null).map(t => t.textContent.trim()),
  };
}, SET);

let now = await state();
check(now.blank, 'a row starts with no activity chosen');
check(now.toggles.length === 0, 'so it offers nowhere to write, got ' + JSON.stringify(now.toggles));

await page.selectOption(SET + ' .bftd-stackrow .bftd-actpick-s', '201');
await page.waitForTimeout(250);
now = await state();
check(!now.blank, 'choosing an activity fills the row');
check(now.toggles.length === 2, 'and brings out notes and work samples, got ' + JSON.stringify(now.toggles));

/* Change is change in both directions. A row cleared back to nothing must not
   leave a tutor typing into something the save will drop. */
await page.evaluate(sel => {
  const s = document.querySelector(sel + ' .bftd-stackrow .bftd-actpick-s');
  s.value = '';
  jQuery(s).trigger('change');
}, SET);
await page.waitForTimeout(250);
now = await state();
check(now.blank, 'clearing it makes the row blank again');
check(now.toggles.length === 0, 'and puts the panels away');

/* A new row added by the button starts blank too, or the very first thing a
   tutor sees after pressing Add activity is two links that do nothing useful. */
await page.click(SET + ' .bftd-row-add');
await page.waitForTimeout(250);
const added = await page.evaluate(sel => {
  const rows = [...document.querySelectorAll(sel + ' .bftd-stackrow')];
  const r = rows[rows.length - 1];
  return {
    blank: r.classList.contains('is-blank'),
    toggles: [...r.querySelectorAll('.bftd-rowpanel-t')].filter(t => t.offsetParent !== null).length,
  };
}, SET);
check(added.blank && added.toggles === 0, 'a freshly added row offers nothing either');

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
