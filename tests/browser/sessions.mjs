/*
 * The sessions screen, driven.
 *
 * The data behind it is checked elsewhere. What only a browser answers is
 * whether the drawer works: whether it stays shut until asked, whether it
 * asks once rather than on every click, whether paging inside it reaches
 * markup that did not exist when the script ran, and whether somebody
 * without a mouse can open a row at all.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-sessions.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await browser.newPage({ viewport: { width: 1340, height: 1000 } });
const errors = [];
p.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

const ROW = '.bftd-ses-r[data-student="1001"]';
const DRAW = '#bftd-ses-1001';

/* ---- nothing is fetched until somebody asks ---- */
check(await p.evaluate(() => window.__posts) === 0,
  'the screen fetches nobody\'s sessions on load');
check(await p.evaluate(s => document.querySelector(s).hidden, DRAW),
  'and every drawer starts shut');
check(await p.evaluate(r => document.querySelector(r + ' .bftd-ses-open').getAttribute('aria-expanded'), ROW) === 'false',
  'which the button says out loud');

/* The row itself has to earn its place: the whole point is that the child,
   the client and the tutor are written once rather than on every session. */
const cols = await p.evaluate(() => [...document.querySelectorAll('.bftd-stu-sec:first-of-type .bftd-ses-t thead th')].map(t => t.textContent.trim()));
check(cols.join() === 'Student,Client,Attendance', 'a student row is the student, the client and the record, got ' + cols.join());

const rec = await p.evaluate(r => document.querySelector(r + ' [data-l="Attendance"]').textContent.replace(/\s+/g, ' ').trim(), ROW);
check(/\d+ attended/.test(rec) && /\d+ missed/.test(rec), 'read in words as well as colour, got ' + JSON.stringify(rec));
check(/\d+ draft/.test(rec), 'with the unfinished ones counted apart, got ' + JSON.stringify(rec));

/* The link beside the name goes to that student's own record, the same
   screen the Students list calls Edit, and says what it does. */
const view = await p.evaluate(r => { const a = document.querySelector(r + ' .bftd-stu-acts a'); return a ? [a.textContent.trim(), a.getAttribute('href')] : null; }, ROW);
check(view && view[0] === 'View student', 'the first link reads View student, got ' + JSON.stringify(view && view[0]));
check(view && /post\.php\?post=1001&action=edit$/.test(view[1]), 'and opens that student\'s record, got ' + JSON.stringify(view && view[1]));

/* ---- opening it ---- */
await p.click(ROW + ' .bftd-ses-open');
await p.waitForTimeout(250);

check(await p.evaluate(() => window.__posts) === 1, 'opening a row asks for that student once');
check(!(await p.evaluate(s => document.querySelector(s).hidden, DRAW)), 'and the drawer opens');
check(await p.evaluate(r => document.querySelector(r + ' .bftd-ses-open').getAttribute('aria-expanded'), ROW) === 'true',
  'saying so');

const rows = () => p.evaluate(s => document.querySelectorAll(s + ' .bftd-ses-inner tbody tr').length, DRAW);
check(await rows() === 20, 'twenty sessions, got ' + await rows());

const shows = () => p.evaluate(s => document.querySelector(s + ' .bftd-ses-of').textContent.trim(), DRAW);
check(/^1.20 of 47$/.test(await shows()), 'saying which twenty of how many, got ' + await shows());

/* ---- shutting it, and opening it again ---- */
await p.click(ROW + ' .bftd-ses-open');
await p.waitForTimeout(120);
check(await p.evaluate(s => document.querySelector(s).hidden, DRAW), 'it shuts again');

await p.click(ROW + ' .bftd-ses-open');
await p.waitForTimeout(250);
/* Fetched once. A drawer that asks again on every open is a screen that
   hammers the database for something it is already holding. */
check(await p.evaluate(() => window.__posts) === 1, 'and re-opening it asks for nothing new');

/* ---- paging inside it ---- */
/* The buttons arrived after the script ran, so they only work if they were
   delegated. Bound directly they do nothing at all, and the drawer looks
   like a list with a dead row of numbers under it. */
await p.click(DRAW + ' .bftd-ses-p[data-page="3"]');
await p.waitForTimeout(250);
check(await p.evaluate(() => window.__posts) === 2, 'turning a page asks for that page');
check(await rows() === 7, 'the last page is the remainder, got ' + await rows());
check(/^41.47 of 47$/.test(await shows()), 'and says where you are, got ' + await shows());
check(await p.evaluate(s => document.querySelector(s + ' .bftd-ses-p.is-on').textContent.trim(), DRAW) === '3',
  'with the page you are on marked');

/* ---- a session row ---- */
const first = await p.evaluate(s => {
  const tr = document.querySelector(s + ' .bftd-ses-inner tbody tr');
  return [...tr.querySelectorAll('td')].map(t => t.textContent.replace(/\s+/g, ' ').trim());
}, DRAW);
check(/Session \d+/.test(first[0]), 'says which session it is, got ' + JSON.stringify(first[0]));
check(/\d{4}/.test(first[1]), 'and when, got ' + JSON.stringify(first[1]));
check(/Attended|Rescheduled|Missed|Not recorded/.test(first[2]), 'and what happened, got ' + JSON.stringify(first[2]));
check(/Published|Draft/.test(first[3]), 'and whether the family can see it, got ' + JSON.stringify(first[3]));

/* ---- the keyboard ---- */
const key = await p.evaluate(async () => {
  const b = document.querySelector('.bftd-ses-r[data-student="1002"] .bftd-ses-open');
  b.focus();
  const focused = document.activeElement === b;
  b.click();
  return { focused };
});
await p.waitForTimeout(250);
check(key.focused, 'a row can be reached by keyboard');
check(!(await p.evaluate(() => document.querySelector('#bftd-ses-1002').hidden)), 'and opened by it');

/* A student with fewer than twenty has no page strip to tab past. */
check(await p.evaluate(() => document.querySelectorAll('#bftd-ses-1002 .bftd-ses-p').length) === 0,
  'a student with one page is not offered page buttons');

/* ---- narrow ---- */
await p.setViewportSize({ width: 700, height: 900 });
await p.waitForTimeout(150);
check(!(await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)),
  'at 700px nothing runs off the side');

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
