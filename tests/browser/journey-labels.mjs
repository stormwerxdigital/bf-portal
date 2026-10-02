/*
 * No two words on the journey chart may touch.
 *
 * The chart's labels share a fixed width between them: every reading added
 * narrows the gap between the last reading's name and the word under the
 * target. The rule that decides when to drop one is arithmetic on a character
 * width, and arithmetic on a character width is a guess until a browser has
 * measured the actual glyphs.
 *
 * So this draws the chart at every number of readings it will be asked for,
 * with the target reached and not, and reads the real bounding boxes back.
 * Text overlapping text is the one thing a parent cannot read past.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-journey.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 900, height: 1000 } });
await page.goto('file://' + page_path);
await page.waitForTimeout(300);

const cases = await page.evaluate(() => [...document.querySelectorAll('.case')].map(c => {
  const svg = c.querySelector('svg.journey');
  const texts = [...svg.querySelectorAll('text')].map(t => {
    const b = t.getBBox();
    return { s: t.textContent.trim(), x: b.x, y: b.y, w: b.width, h: b.height };
  });
  const vb = svg.viewBox.baseVal;
  return { n: +c.dataset.n, reached: c.dataset.reached === '1', texts, vw: vb.width, vh: vb.height };
}));

check(cases.length === 14, 'fourteen charts drawn, got ' + cases.length);

/* Two labels overlap when their boxes overlap on both axes. A shared edge is
   not an overlap; a single shared pixel is. */
const hits = (a, b) =>
  a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

for (const c of cases) {
  const name = `${c.n} reading${c.n === 1 ? '' : 's'}${c.reached ? ', reached' : ''}`;
  const bad = [];
  for (let i = 0; i < c.texts.length; i++) {
    for (let k = i + 1; k < c.texts.length; k++) {
      if (hits(c.texts[i], c.texts[k])) bad.push(c.texts[i].s + ' / ' + c.texts[k].s);
    }
  }
  check(bad.length === 0, `${name}: nothing overlaps${bad.length ? ' — ' + JSON.stringify(bad) : ''}`);

  const out = c.texts.filter(t => t.x < -0.5 || t.x + t.w > c.vw + 0.5 || t.y < -0.5 || t.y + t.h > c.vh + 0.5);
  check(out.length === 0, `${name}: nothing runs off the edge${out.length ? ' — ' + JSON.stringify(out.map(t => t.s)) : ''}`);

  /* And the target is always named, however crowded it gets: the whole chart
     is about where the child is going. */
  check(c.texts.some(t => /^Target/.test(t.s)), `${name}: the target is still named`);
}

await browser.close();
process.exit(fail ? 1 : 0);
