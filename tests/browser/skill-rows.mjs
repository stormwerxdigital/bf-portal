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
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-skill-rows.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

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
check(bare.rows === 18, 'with no script, the whole library is on the page, got ' + bare.rows);
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
check(s.total === 18, 'every skill in the library has a row, got ' + s.total);
check(s.shown === 10, 'ten of them show, got ' + s.shown);
check(s.btnShown, 'and the button appears once it can do something');
check(/Show all 18 skills/.test(s.label), 'saying how many there are, got ' + JSON.stringify(s.label));

/* The two states have to be visibly different, or the list is just a list. */
check(s.on === 5 && s.ahead === 13, `five being built, thirteen ahead, got ${s.on} and ${s.ahead}`);

/* Reached first. Opening on a run of things nobody has taught yet is a list
   that starts with what has not happened. */
check(s.first.every(n => n.length > 0), 'the rows are named');
const onFirst = await p.evaluate(() =>
  [...document.querySelectorAll('.skl-r')].slice(0, 5).every(e => e.classList.contains('is-on')));
check(onFirst, 'and the first five rows are the ones being built');

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

/* The two states are told apart by the bullet: filled for reached, empty for
   ahead. Nothing is written to the right of the name: Karl removed that
   column. */
await p.mouse.move(0, 0);
await p.waitForTimeout(100);
const told = await p.evaluate(() => {
  const on = document.querySelector('.skl-r.is-on');
  const ah = document.querySelector('.skl-r.is-ahead');
  const cs = e => getComputedStyle(e);
  return {
    onMark: cs(on.querySelector('.skl-mark')).backgroundColor,
    ahMark: cs(ah.querySelector('.skl-mark')).backgroundColor,
    words: document.querySelectorAll('.skl-r .skl-m, .skl-r svg').length,
  };
});
check(told.onMark !== told.ahMark, 'the mark on a reached row is filled differently');
check(told.words === 0, 'and no words or ticks to the right of a name, got ' + told.words);

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
