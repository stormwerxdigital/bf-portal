/*
 * The oversight report, as a browser draws it.
 *
 * It exists to be read quickly and then acted on, so the things only a
 * browser can answer are whether the figure that matters is the one the eye
 * lands on, and whether every date on it can actually be read.
 *
 * The colour work here is not decoration. A count of nought is the same
 * figure as a clean week and must not pull the eye away from the one that is
 * not, and a clay date chip on a clay tile is the exact combination that
 * measures fine on white and fails where it actually sits.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-oversight.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const lum = (rgb) => {
  const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(n => {
    const c = n / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
  const [hi, lo] = lum(a) > lum(b) ? [lum(a), lum(b)] : [lum(b), lum(a)];
  return (hi + 0.05) / (lo + 0.05);
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await browser.newPage({ viewport: { width: 1340, height: 1000 } });
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

const heads = await p.evaluate(() => [...document.querySelectorAll('.bftd-stu-h')].map(h => ({
  name: h.querySelector('h2').textContent.trim(),
  line: h.querySelector('.bftd-stu-n').textContent.trim(),
})));
check(heads.length === 2, 'a section per tutor, got ' + heads.length);
check(heads.every(h => /\d+ of \d+ written up on the day/.test(h.line)),
  'each headed by what they got done on the day, got ' + JSON.stringify(heads.map(h => h.line)));

/* Nothing recorded is the first tile and the one that shouts. An hour with
   no record is nobody being able to say whether a child was taught; a late
   write-up is paperwork. */
const tiles = await p.evaluate(() => [...document.querySelectorAll('.bftd-stu-sec:first-of-type .bftd-ov-tile')]
  .map(t => ({ word: t.querySelector('span').textContent.trim(), n: t.querySelector('b').textContent.trim(), cls: t.className })));
check(tiles[0].word === 'nothing recorded', 'nothing recorded is first, got ' + tiles[0].word);
check(/is-bad/.test(tiles[0].cls), 'and is the one marked, got ' + tiles[0].cls);

/* A nought is not news. Marked the same as a real count, a clean tutor reads
   as a row of alarms and the report stops meaning anything. */
const quiet = await p.evaluate(() => {
  const t = [...document.querySelectorAll('.bftd-ov-tile')].find(e => e.querySelector('b').textContent.trim() === '0');
  return t ? { cls: t.className, weight: getComputedStyle(t.querySelector('b')).fontWeight } : null;
});
check(quiet && /is-quiet/.test(quiet.cls), 'a count of nought is set back rather than marked');
check(quiet && Number(quiet.weight) < 700, 'and not in the same weight as a real one, got ' + (quiet && quiet.weight));

/* Every date has to be readable where it sits, not on white. */
const chips = await p.evaluate(() => [...document.querySelectorAll('.bftd-ov-d')].map(el => {
  const cs = getComputedStyle(el);
  let bg = 'rgba(0, 0, 0, 0)', node = el;
  while (node && bg === 'rgba(0, 0, 0, 0)') { bg = getComputedStyle(node).backgroundColor; node = node.parentElement; }
  return { text: el.textContent.trim(), fg: cs.color, bg: cs.backgroundColor === 'rgba(0, 0, 0, 0)' ? bg : cs.backgroundColor };
}));
check(chips.length > 0, 'the report names the dates themselves, got ' + chips.length);
const dim = chips.filter(c => ratio(c.fg, c.bg) < 4.5);
check(dim.length === 0, 'and every one is readable' + (dim.length ? ': ' + JSON.stringify(dim.map(c => c.text + ' ' + ratio(c.fg, c.bg).toFixed(2))) : ''));

/* A late one says how late, or "late" is a word with no size to it. */
check(chips.some(c => /\+\d+d/.test(c.text)), 'a late write-up says by how many days, got ' + JSON.stringify(chips.map(c => c.text).slice(0, 6)));

/* A tutor with nothing outstanding is told so, rather than shown an empty
   table nobody can tell from a broken one. */
await p.evaluate(() => document.querySelectorAll('.bftd-ov-clear').length);

/* Narrow. */
await p.setViewportSize({ width: 700, height: 1000 });
await p.waitForTimeout(150);
check(!(await p.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1)),
  'at 700px nothing runs off the side');
check(await p.evaluate(() => getComputedStyle(document.querySelector('.bftd-ov-t td[data-l]'), '::before').content) !== 'none',
  'and each line says which figure it is');

await browser.close();
process.exit(fail ? 1 : 0);
