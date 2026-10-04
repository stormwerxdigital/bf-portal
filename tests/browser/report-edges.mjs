/*
 * Two things the report must do with nothing around it, because the preview
 * has no theme to do them for it.
 *
 * Text meant for screen readers only ("Done." beside each skill) is not drawn.
 * It is hidden by the report's own stylesheet now; it used to rely on the
 * theme, so the preview showed it.
 *
 * The last panel in an open session keeps its padding and its bottom edge.
 * The rule that takes the divider off the last plain block used to outweigh
 * the panel's own, and the panel looked cut off.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import fs from 'node:fs';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const here = dirname(fileURLToPath(import.meta.url));
execFileSync('php', [here + '/build-report.php']);

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1100, height: 900 } });

/* The real progress report, with no theme around it. */
await page.goto('file://' + here + '/report.html');
const sr = await page.$$eval('.bf-report .screen-reader-text', l => l.map(e => {
  const r = e.getBoundingClientRect();
  return { w: r.width, h: r.height, text: e.textContent.trim() };
}));
check(sr.length > 5, 'the report has screen reader text to hide, got ' + sr.length);
check(sr.every(s => s.w <= 1 && s.h <= 1), 'none of it is drawn: ' + JSON.stringify(sr.filter(s => s.w > 1 || s.h > 1).slice(0, 3)));

/* An open session with every kind of block in it, last block each time. */
const css = fs.readFileSync(here + '/../../assets/css/bftd-report.css', 'utf8');
const kinds = ['blk-skills', 'blk-read', 'blk-hw', 'blk-home', 'blk-new', ''];
const body = kinds.map(k => `<div class="session is-open"><div class="acc"><div class="acc-in"><div class="acc-pad">
  <div class="blk blk-skills"><div class="blk-h">First</div><p>One</p></div>
  <div class="blk ${k}" data-k="${k || 'plain'}"><div class="blk-h">Last</div><p>Two</p></div>
</div></div></div></div>`).join('');
await page.setContent(`<!doctype html><style>${css}</style><div class="bf-report" data-theme="light">${body}</div>`);
const last = await page.$$eval('[data-k]', l => l.map(e => {
  const s = getComputedStyle(e);
  return { k: e.dataset.k, pad: parseFloat(s.paddingBottom), edge: parseFloat(s.borderBottomWidth) };
}));
for (const b of last) {
  if ('plain' === b.k) {
    check(b.pad === 0 && b.edge === 0, 'a plain last block still drops its divider, got ' + JSON.stringify(b));
  } else {
    check(b.pad >= 14 && b.edge >= 1, `a ${b.k} panel that comes last keeps its padding and bottom edge, got ` + JSON.stringify(b));
  }
}

await browser.close();
process.exit(fail ? 1 : 0);
