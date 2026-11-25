/*
 * The student switcher on the portal's bar (Karl, 1.219.2: "All students
 * needs to be in white, and we need to be able to see students in the
 * dropdown list"). The real portal styles; made-up names.
 */
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const css = readFileSync(here + '/../../assets/css/bftd-portal.css', 'utf8');
const page_path = here + '/portal-switch.html';
writeFileSync(page_path, `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head><body>
<div class="bf-portal"><div class="bf-bar"><div class="bf-mark">Brilliant Futures <span>Tutoring</span></div>
<a class="bf-allkids" href="#all">All students</a>
<select class="bf-switch"><option selected>Ada Test</option><option>Ben Test</option></select></div></div></body></html>`);

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };
const browser = await chromium.launch(launchOptions);
const p = await browser.newPage();
await p.goto('file://' + page_path);

const c = await p.evaluate(() => {
  const a = document.querySelector('.bf-allkids');
  const o = document.querySelectorAll('.bf-switch option')[1];
  return { link: getComputedStyle(a).color, optColor: getComputedStyle(o).color, optBg: getComputedStyle(o).backgroundColor };
});
check(c.link === 'rgb(255, 255, 255)', 'All students is white on the bar, got ' + c.link);
check(c.optColor === 'rgb(29, 35, 39)' && c.optBg === 'rgb(255, 255, 255)', 'the students in the open list are dark on white, got ' + c.optColor + ' on ' + c.optBg);

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
