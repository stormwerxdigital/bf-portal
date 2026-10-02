/*
 * A lesson row whose activity went in the trash.
 *
 * It is already gone from the family's report, because the report resolves
 * rows through the library and the library is publish-only. On the tutor's
 * screen it was still drawn: an empty picker, with "Add notes" and "Add work
 * samples" under it, inviting somebody to write about an activity that is not
 * there. So it is not drawn any more.
 *
 * Not drawn is not the same as dropped, and the difference is the whole
 * reason this test is in a browser. The row's values ride back as hidden
 * inputs so that trash stays undoable — and the script renumbers rows by
 * walking .bftd-row. Inputs outside that walk keep whatever index PHP printed
 * them with, so adding or dragging a row hands two rows the same index, PHP
 * reads the second over the first, and the kept row is destroyed by the very
 * save it was supposed to survive. Nothing throws. Nothing looks wrong. The
 * only way to see it is to press the button.
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
const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });

await page.goto('file://' + page_path);
await page.waitForTimeout(250);

const SET = '#trashed .bftd-rows';

/* What the form would post, exactly as a browser would send it. */
const posted = () => page.evaluate(sel => {
  const out = {};
  document.querySelectorAll(sel + ' [name^="bftd_rows"]').forEach(el => {
    const m = el.getAttribute('name').match(/\[activities\]\[(\d+)\]\[(\w+)\]/);
    if (!m) return;
    (out[m[1]] = out[m[1]] || {})[m[2]] = el.value;
  });
  return out;
}, SET);

const shown = () => page.evaluate(sel => ({
  visible: [...document.querySelectorAll(sel + ' .bftd-stackrow')].filter(r => r.offsetParent !== null).length,
  rows: document.querySelectorAll(sel + ' .bftd-row').length,
  height: document.querySelector(sel + ' .bftd-rowkept').getBoundingClientRect().height,
  writeHere: document.querySelectorAll(sel + ' .bftd-rowkept .bftd-rowpanel-t, ' + sel + ' .bftd-rowkept .bftd-actpick').length,
}), SET);

let look = await shown();
check(look.visible === 1, 'one of the two rows is drawn, got ' + look.visible);
check(look.rows === 2, 'while both still count as rows, got ' + look.rows);
check(look.height === 0, 'the kept one taking no space at all, got ' + look.height);
check(look.writeHere === 0, 'and offering nobody anywhere to write about it');

let sent = await posted();
check(sent['0'] && sent['0'].id === '299', 'the trashed activity is still posted');
check(sent['0'] && sent['0'].note === '<p>he read it twice</p>', 'with the note written against it');
check(sent['0'] && sent['0'].samples === '7,8', 'and its work samples, as a list');
check(sent['1'] && sent['1'].id === '201', 'and the live row is the second, not the first');

/* The button. */
await page.click(SET + ' .bftd-row-add');
await page.waitForTimeout(250);

look = await shown();
check(look.rows === 3, 'adding a row adds one, got ' + look.rows);

sent = await posted();
const ids = Object.keys(sent).sort();
check(ids.length === 3, 'three rows post under three indexes, got ' + JSON.stringify(ids));
check(sent['0'] && sent['0'].id === '299',
  'and the kept row still has its own, so nothing is written over it: ' + JSON.stringify(sent['0']));
check(sent['0'] && sent['0'].note === '<p>he read it twice</p>', 'note and all');

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
