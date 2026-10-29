/*
 * One part of a session at a time (Laurel, 1.161).
 *
 * "When clicking on one of the activity headings, the whole session's
 * activities open, not just the one clicked." A tag in a session's head now
 * opens the session at the part it names, with the others folded; the head
 * itself still opens everything. Driven in a browser because the whole of it
 * is what a click does.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

import { chromium, launchOptions } from './pw.mjs';
const here = dirname(fileURLToPath(import.meta.url));

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

execFileSync('php', [join(here, 'build-lessons.php')]);

const browser = await chromium.launch(launchOptions);
const page = await browser.newPage({ viewport: { width: 1000, height: 900 } });
const errors = [];
page.on('pageerror', e => errors.push(String(e)));
await page.goto('file://' + join(here, 'lessons.html'));

/* A session with an activity part and at least one other part to fold away. */
const sid = await page.evaluate(() => {
  for (const s of document.querySelectorAll('.session')) {
    const tags = [...s.querySelectorAll('.session-head .tag-act[data-part]')]
      .filter(t => document.getElementById(t.dataset.part));
    if (tags.some(t => !t.classList.contains('tag-part')) && s.querySelectorAll('details.blk').length > 1) return s.id;
  }
  return '';
});
check(!!sid, 'the sample has a session with an activity and other parts, got ' + sid);

const state = () => page.evaluate(id => {
  const s = document.getElementById(id);
  return { open: s.classList.contains('is-open'),
           parts: [...s.querySelectorAll('details.blk')].map(d => ({ id: d.id, open: d.open })) };
}, sid);

/* Every part ships open, so a page with scripts blocked reads in full. */
check((await state()).parts.every(p => p.open), 'every part ships open');

/* An activity's tag opens the session at that activity alone. */
const actPart = await page.evaluate(id => {
  const t = [...document.querySelectorAll('#' + id + ' .session-head .tag-act[data-part]')]
    .find(t => !t.classList.contains('tag-part') && document.getElementById(t.dataset.part));
  return t.dataset.part;
}, sid);
await page.click(`#${sid} .tag-act[data-part="${actPart}"]`);
await page.waitForTimeout(450);
let st = await state();
check(st.open, 'clicking an activity tag opens the session');
check(st.parts.filter(p => p.open).map(p => p.id).join() === actPart,
  'with only that activity unfolded, got ' + JSON.stringify(st.parts.filter(p => p.open).map(p => p.id)));
const inView = await page.evaluate(p => { const r = document.getElementById(p).getBoundingClientRect(); return r.top >= 0 && r.top < innerHeight; }, actPart);
check(inView, 'and brought into view');

/* The notes tag, on the same open session, swaps to the notes. */
const notes = await page.evaluate(id => { const t = document.querySelector('#' + id + ' .tag-part[data-part$="-notes"]'); return t ? t.dataset.part : ''; }, sid);
if (notes) {
  await page.click(`#${sid} .tag-act[data-part="${notes}"]`);
  await page.waitForTimeout(450);
  st = await state();
  check(st.open && st.parts.filter(p => p.open).map(p => p.id).join() === notes, 'the Tutor session notes tag shows the notes alone');
}

/* A part's own heading still opens and closes it. */
await page.click(`#${actPart} > summary`);
st = await state();
check(st.parts.find(p => p.id === actPart).open, 'a part\'s heading opens it as well');

/* The head closes the session, and opening it from the head shows everything. */
await page.click(`#${sid} .session-title`);
await page.waitForTimeout(350);
check(!(await state()).open, 'the head closes the session');
await page.click(`#${sid} .session-title`);
await page.waitForTimeout(350);
st = await state();
check(st.open && st.parts.every(p => p.open), 'and opened from the head, every part shows');

check(errors.length === 0, 'nothing threw: ' + JSON.stringify(errors));

await browser.close();
console.log(fail ? `\n${fail} failed` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
