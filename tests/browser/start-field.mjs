/*
 * History's Attending from (Karl, 1.217.1: "design here is broken, needs to
 * be improved"): the menu fits its column, New appointment's fields stay
 * hidden until it is chosen, and then sit inside the column too.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-start-field.php']).toString().trim();
let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);
const p = await browser.newPage({ viewport: { width: 1200, height: 900 } });
const errors = [];
p.on('pageerror', e => errors.push(e.message));
await p.goto('file://' + page_path);
await p.waitForTimeout(150);

const m = () => p.evaluate(() => {
  const cell = document.querySelector('#cell').getBoundingClientRect();
  const sel = document.querySelector('#bftd-start-pick').getBoundingClientRect();
  const box = document.querySelector('.bftd-start-new');
  const b = box.getBoundingClientRect();
  const inner = [...box.querySelectorAll('input,select')].map(e => e.getBoundingClientRect().right);
  return { cellR: cell.right, selR: sel.right, shown: getComputedStyle(box).display !== 'none' && b.height > 0, boxR: b.right, innerR: inner.length ? Math.max(...inner) : 0 };
});

/* Level with the field beside it (Karl, 1.217.2: "properly align these"). */
const tops = await p.evaluate(() => ({ menu: Math.round(document.querySelector('#bftd-start-pick').getBoundingClientRect().top), beside: Math.round(document.querySelector('.grid4 > .bftd-field input').getBoundingClientRect().top), now: Math.round(document.querySelector('.bftd-start-now').getBoundingClientRect().top) }));
check(Math.abs(tops.menu - tops.beside) <= 1, 'the menu lines up with the box in the next column, got ' + JSON.stringify(tops));
check(tops.now > tops.menu, 'and what it is set to is said under it, not above');
check(errors.length === 0, 'the admin script loads without errors' + (errors.length ? ': ' + errors[0] : ''));
let s = await m();
check(!s.shown, 'New appointment\'s fields are hidden while an appointment is being chosen');
check(s.selR <= s.cellR + 0.5, 'the menu fits its column, got right edge ' + Math.round(s.selR) + ' against ' + Math.round(s.cellR));

await p.selectOption('#bftd-start-pick', 'new'); await p.waitForTimeout(80);
s = await m();
check(s.shown, 'choosing New appointment shows its fields');
check(s.boxR <= s.cellR + 0.5 && s.innerR <= s.cellR + 0.5, 'and they fit the column too, got ' + Math.round(s.innerR) + ' against ' + Math.round(s.cellR));
await p.selectOption('#bftd-start-pick', { index: 1 }); await p.waitForTimeout(80);
check(!(await m()).shown, 'and hide again when an appointment is chosen');

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
