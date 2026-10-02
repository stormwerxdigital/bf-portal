/*
 * The students screen, as a browser renders it.
 *
 * The data behind it is checked elsewhere. What only a browser can answer is
 * whether the thing a person looks at works: whether the section headings
 * actually read as headings, whether the row actions can be reached without
 * a mouse, and whether a screen built for a thousand students collapses into
 * an unreadable smear at the width people actually have.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-students.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await browser.newPage({ viewport: { width: 1340, height: 1000 } });
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

const sections = await p.evaluate(() => [...document.querySelectorAll('.bftd-stu-sec')].map(s => ({
  name: s.querySelector('h2').textContent.trim(),
  count: s.querySelector('.bftd-stu-n').textContent.trim(),
  rows: s.querySelectorAll('tbody tr').length,
  none: s.classList.contains('is-none'),
})));

check(sections.length === 4, 'a section per tutor, and one for the unassigned, got ' + sections.length);
check(sections[sections.length - 1].none, 'with the unassigned last and marked as its own thing');
check(sections.every(s => /\d+ student/.test(s.count)), 'each saying how many are in it, got ' + JSON.stringify(sections.map(s => s.count)));

/* A section header is the thing the eye travels down, so it has to look like
   one against the rows under it, not like another row. */
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

const head = await p.evaluate(() => {
  const cs = e => getComputedStyle(e);
  const h = document.querySelector('.bftd-stu-h');
  return {
    bg: cs(h).backgroundColor,
    title: cs(h.querySelector('h2')).color,
    count: cs(h.querySelector('.bftd-stu-n')).color,
    only: cs(h.querySelector('.bftd-stu-only')).color,
    rowBg: cs(document.querySelector('.bftd-stu-t tbody tr td')).backgroundColor,
  };
});
check(ratio(head.title, head.bg) >= 4.5, 'the tutor\'s name carries on the band, ' + ratio(head.title, head.bg).toFixed(2) + ':1');
check(ratio(head.count, head.bg) >= 4.5, 'and so does the count beside it, ' + ratio(head.count, head.bg).toFixed(2) + ':1');
check(ratio(head.only, head.bg) >= 4.5, 'and the link, ' + ratio(head.only, head.bg).toFixed(2) + ':1');
check(ratio(head.bg, head.rowBg) >= 3, 'the band stands off the rows under it, ' + ratio(head.bg, head.rowBg).toFixed(2) + ':1');

/* Every figure on a row has to be readable, including the one that is trying
   to catch the eye. */
const low = await p.evaluate(() => {
  const el = document.querySelector('.bftd-stu-left.is-low');
  if (!el) return null;
  return { fg: getComputedStyle(el).color, bg: getComputedStyle(el.closest('td')).backgroundColor };
});
check(low !== null, 'a student running low on sessions is marked');
if (low) check(ratio(low.fg, low.bg) >= 4.5, 'and the mark is still readable, ' + ratio(low.fg, low.bg).toFixed(2) + ':1');

/* Row actions are hidden until the row is under the pointer, the way
   WordPress hides its own. Somebody working by keyboard has no pointer, so
   they have to appear on focus as well or the screen has no Edit link at all
   for them. */
const before = await p.evaluate(() => getComputedStyle(document.querySelector('.bftd-stu-acts')).opacity);
await p.evaluate(() => document.querySelector('.bftd-stu-acts a').focus());
// The reveal is a transition, so what is computed on the same tick is still
// the old value. Read it once it has landed.
await p.waitForTimeout(200);
const acts = await p.evaluate(() => {
  const a = document.querySelector('.bftd-stu-acts');
  return { before: null, after: getComputedStyle(a).opacity, focused: document.activeElement === a.querySelector('a') };
});
acts.before = before;
check(acts.before === '0', 'the row actions are out of the way until wanted');
check(acts.focused, 'and can be reached by keyboard at all, which visibility:hidden would prevent');
check(acts.after === '1', 'and appear when they are, not only under a pointer');

/* The row must not jump when they appear, or reading down a list of a
   hundred names becomes a list that moves under the pointer. */
const jump = await p.evaluate(() => {
  const tr = document.querySelector('.bftd-stu-t tbody tr');
  const was = tr.getBoundingClientRect().height;
  document.querySelector('.bftd-stu-acts a').blur();
  return Math.abs(tr.getBoundingClientRect().height - was);
});
check(jump < 1, 'and the row does not change height when they do, moved ' + jump);

/* The caregivers column has to name somebody whenever somebody can see the
   portal. A dash there says nobody can, and on most families here the person
   who can is also the one paying. */
const care = await p.evaluate(() => [...document.querySelectorAll('.bftd-stu-t td[data-l="Caregivers"]')]
  .map(td => td.textContent.replace(/\s+/g, ' ').trim()));
const linked = care.filter(c => c !== 'Nobody yet');
check(linked.length > 0 && linked.every(c => c !== '—' && c !== ''),
  'a row where somebody has access names them');
/* And one where nobody does says so in words. A dash is read as "no data"
   rather than as "nobody can open this yet", which is a thing to go and fix. */
check(care.includes('Nobody yet'), 'while one where nobody does says so, got ' + JSON.stringify([...new Set(care)].slice(0, 4)));
check(care.some(c => /client/.test(c)), 'and the one who is also the client is marked, got ' + JSON.stringify(care.slice(0, 3)));

const mark = await p.evaluate(() => {
  const el = document.querySelector('.bftd-is-client');
  if (!el) return null;
  return { fg: getComputedStyle(el).color, bg: getComputedStyle(el).backgroundColor };
});
check(mark && ratio(mark.fg, mark.bg) >= 4.5,
  'the marker is readable, ' + (mark ? ratio(mark.fg, mark.bg).toFixed(2) : '?') + ':1');

/* Narrow. A practice looks at this on a laptop beside a video call. */
await p.setViewportSize({ width: 700, height: 900 });
await p.waitForTimeout(150);
const narrow = await p.evaluate(() => ({
  scroll: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  labelled: getComputedStyle(document.querySelector('.bftd-stu-t td[data-l]'), '::before').content,
}));
check(!narrow.scroll, 'at 700px nothing runs off the side');
check(/Client|Caregivers|Tutors|Sessions|Status/.test(narrow.labelled),
  'and each line says what it is, since the column headings are gone, got ' + narrow.labelled);

await browser.close();
process.exit(fail ? 1 : 0);
