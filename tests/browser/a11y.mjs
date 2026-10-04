/*
 * Accessibility mode, measured on two real screens: the students list and a
 * family's progress report.
 *
 * With the mode on, every piece of visible text must be at least 16px, in
 * Atkinson Hyperlegible (the alphabet face excepted), upright, with at least
 * 7:1 contrast against what is behind it; every ordinary link underlined;
 * nothing half transparent; whatever has focus ringed; no box shadows. The
 * same screens without the mode fail several of those, which is what shows
 * the checks are measuring something.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const here = dirname(fileURLToPath(import.meta.url));
execFileSync('php', [here + '/build-a11y.php']);

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

/* Everything wrong with the visible text and links on the page, as a list. */
function problems() {
  const lum = c => { const m = c.match(/[\d.]+/g); const [r, g, b] = m.slice(0, 3).map(v => { v = v / 255; return v <= .03928 ? v / 12.92 : Math.pow((v + .055) / 1.055, 2.4); }); return .2126 * r + .7152 * g + .0722 * b; };
  const alpha = c => { const m = c.match(/[\d.]+/g); return m && m.length > 3 ? +m[3] : 1; };
  const bgOf = el => { for (let e = el; e; e = e.parentElement) { const s = getComputedStyle(e); if (s.backgroundImage !== 'none') return null; if (alpha(s.backgroundColor) > .9) return s.backgroundColor; } return 'rgb(255,255,255)'; };
  const shown = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 1 && r.height > 1 && s.visibility !== 'hidden' && !el.closest('[hidden],.screen-reader-text,[aria-hidden=true],svg,script,style,textarea,option'); };
  const out = [];
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  while (w.nextNode()) {
    const el = w.currentNode.parentElement;
    if (!w.currentNode.textContent.trim() || !el || !shown(el)) continue;
    const s = getComputedStyle(el);
    const name = el.tagName + '.' + el.className + ' "' + el.textContent.trim().slice(0, 30) + '"';
    if (parseFloat(s.fontSize) < 16) out.push('small ' + s.fontSize + ' ' + name);
    if (s.fontStyle !== 'normal') out.push('italic ' + name);
    if (!/Atkinson/.test(s.fontFamily) && !/Gothic/.test(s.fontFamily)) out.push('typeface ' + s.fontFamily.slice(0, 20) + ' ' + name);
    let op = 1; for (let e = el; e; e = e.parentElement) op *= +getComputedStyle(e).opacity;
    if (op < .95) out.push('faded ' + name);
    const bg = bgOf(el);
    if (bg) { const a = lum(s.color), b = lum(bg); const r = (Math.max(a, b) + .05) / (Math.min(a, b) + .05); if (r < 7) out.push('contrast ' + r.toFixed(2) + ' ' + name); }
  }
  for (const a of document.querySelectorAll('a[href]')) {
    if (!shown(a) || !a.textContent.trim() || a.matches('.button,.page-title-action,.bf-btn,.btn,.bftd-pill,.chip,.tabbtn')) continue;
    if (!/underline/.test(getComputedStyle(a).textDecorationLine)) out.push('not underlined "' + a.textContent.trim().slice(0, 30) + '"');
  }
  for (const el of document.querySelectorAll('.bf-report .card, .bf-report .kpit, .bf-report .session, .bftd-wrap table')) {
    if (getComputedStyle(el).boxShadow !== 'none') out.push('shadow ' + el.className);
  }
  return out;
}

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });

for (const [name, file] of [['the students list', 'students'], ['the progress report', 'report']]) {
  await page.goto('file://' + here + '/' + file + '.html');
  const before = await page.evaluate(problems);
  check(before.length > 5, `${name} without the mode has things the checks catch (${before.length}), so they are real`);

  await page.goto('file://' + here + '/a11y-' + file + '.html');
  check(await page.evaluate(() => document.body.classList.contains('bftd-a11y')), `${name}: the mode is on`);
  const after = await page.evaluate(problems);
  check(after.length === 0, `${name} with the mode on has none: ${after.slice(0, 8).join(' | ')}`);

  // A focused link is ringed thickly enough to find.
  const ring = await page.evaluate(() => {
    const a = [...document.querySelectorAll('a[href], button')].find(x => x.getBoundingClientRect().width > 0);
    a.focus();
    const s = getComputedStyle(a);
    return [s.outlineStyle, parseFloat(s.outlineWidth), s.boxShadow];
  });
  check(ring[0] === 'solid' && ring[1] >= 3 && /255, 214, 10/.test(ring[2]), `${name}: whatever has focus gets the black and yellow ring, got ${ring}`);
}

/* Printing is untouched: the rules are for the screen only. */
await page.goto('file://' + here + '/a11y-report.html');
await page.emulateMedia({ media: 'print' });
const printed = await page.evaluate(() => getComputedStyle(document.querySelector('.bf-report h1, .bf-report h2')).fontFamily);
check(!/Atkinson/.test(printed), 'printing a report prints it as the family gets it, got ' + printed);

check(errors.length === 0, 'no script errors ' + errors.join(' | '));
await browser.close();
process.exit(fail ? 1 : 0);
