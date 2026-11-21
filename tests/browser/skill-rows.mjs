/*
 * The skills list, folded.
 *
 * Eighteen rows is more than anybody reads at the top of a report, so ten
 * show and the rest fold away behind a button. What matters is which way
 * round that is built: every row ships VISIBLE and the script folds the tail,
 * so a report with scripts blocked, or printed, is the whole library rather
 * than ten rows and a button that does nothing. Nothing in the source says
 * which way round it is.
 *
 * So the page is loaded twice, once with JavaScript turned off.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-skill-rows.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);

/* ---- scripts blocked ---- */
const noJs = await browser.newContext({ javaScriptEnabled: false });
const p0 = await noJs.newPage();
await p0.goto('file://' + page_path);
const bare = await p0.evaluate(() => ({
  rows: [...document.querySelectorAll('.skl-r')].filter(e => e.offsetParent !== null).length,
  btn: (document.querySelector('.skl-all') || {}).offsetParent !== undefined
    && document.querySelector('.skl-all') !== null
    && document.querySelector('.skl-all').offsetParent !== null,
}));
check(bare.rows === 18, 'with no script, every skill reached is on the page, got ' + bare.rows);
check(!bare.btn, 'and the button is not shown, because it would do nothing');
await noJs.close();

/* ---- scripts on ---- */
const p = await browser.newPage({ viewport: { width: 860, height: 900 } });
const errors = [];
p.on('pageerror', e => errors.push(String(e)));
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

const state = () => p.evaluate(() => {
  const vis = [...document.querySelectorAll('.skl-r')].filter(e => e.offsetParent !== null);
  const btn = document.querySelector('.skl-all');
  return {
    shown: vis.length,
    total: document.querySelectorAll('.skl-r').length,
    on: document.querySelectorAll('.skl-r.is-on').length,
    ahead: document.querySelectorAll('.skl-r.is-ahead').length,
    first: vis.slice(0, 5).map(e => e.querySelector('.skl-n').textContent.trim()),
    label: btn.textContent.trim(),
    expanded: btn.getAttribute('aria-expanded'),
    btnShown: btn.offsetParent !== null,
  };
});

let s = await state();
check(s.total === 18, 'every skill reached has a row, got ' + s.total);
check(s.shown === 10, 'ten of them show, got ' + s.shown);
check(s.btnShown, 'and the button appears once it can do something');
check(s.label.trim() === 'Show all skills', 'reading "Show all skills", got ' + JSON.stringify(s.label));

/* Every row is a skill reached on a session (1.208): nothing is listed as
   still to do, so every row is a checkmark. This report's own five come
   first, then the thirteen from earlier sessions. */
check(s.on === 18 && s.ahead === 0, `eighteen reached, none still to do, got ${s.on} and ${s.ahead}`);
check(s.first.every(n => n.length > 0), 'the rows are named');
check(s.first[0] === 'Forming lowercase letters automatically', 'and this report\'s own come first, got ' + JSON.stringify(s.first[0]));

await p.click('.skl-all');
await p.waitForTimeout(150);
s = await state();
check(s.shown === 18, 'the button opens the rest, got ' + s.shown);
check(/Show fewer/.test(s.label), 'and says so, got ' + JSON.stringify(s.label));
check(s.expanded === 'true', 'with the state announced to a screen reader');

await p.click('.skl-all');
await p.waitForTimeout(150);
s = await state();
check(s.shown === 10, 'and folds them away again, got ' + s.shown);
check(s.expanded === 'false', 'announcing that too');

/* A reached skill is a checkmark with no dot behind it. Nothing is written
   to the right of the name: Karl removed that column. */
await p.mouse.move(0, 0);
await p.waitForTimeout(100);
const told = await p.evaluate(() => {
  const on = document.querySelector('.skl-r.is-on');
  const cs = e => getComputedStyle(e);
  return {
    words: document.querySelectorAll('.skl-r .skl-m').length,
    allTicked: [...document.querySelectorAll('.skl-r')].every(e => !!e.querySelector('.skl-mark svg')),
    onDot: cs(on.querySelector('.skl-mark')).backgroundColor,
    next: document.querySelectorAll('.skl-next').length,
  };
});
check(told.words === 0, 'no words to the right of a name, got ' + told.words);
check(told.allTicked, 'every row is a checkmark');
check(told.next === 0, 'and no line about what comes next');
check(/rgba\(0, 0, 0, 0\)|transparent/.test(told.onDot), 'and the checkmark has no dot behind it, got ' + told.onDot);

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
