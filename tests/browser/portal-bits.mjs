/*
 * The folded description, the tutor's notes, and the work lightbox, driven in
 * a browser.
 *
 * A fold that never folds looks like a long page, and a lightbox that never
 * opens looks like an ordinary link. Neither shows up in a unit test and
 * neither throws.
 */
import { execSync } from 'child_process';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';
const here = dirname(fileURLToPath(import.meta.url));
execSync('php ' + join(here, 'build-portal-bits.php'), { stdio: 'pipe' });

const root = execSync('npm root -g').toString().trim();
const { chromium } = await import(root + '/playwright/index.mjs');

let fail = 0;
const check = (ok, msg) => { console.log((ok ? '  ok   ' : 'FAIL   ') + msg); if (!ok) fail++; };

const b = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const p = await b.newPage();
await p.goto('file://' + join(here, 'portal-bits.html'));
await p.waitForTimeout(300);

/* ---- the fold ----
 * Drawn by the real activity_block(). A details element, so it folds with no
 * script at all; the checks below would pass with scripts switched off. */
const fold = () => p.evaluate(() => {
  const d = document.querySelectorAll('details.about-fold')[0];
  const vis = el => !!el && el.checkVisibility();
  return {
    open: d.open,
    label: d.querySelector('.about-k').textContent.trim(),
    gistShown: vis(d.querySelector('.about-gist')),
    gist: (d.querySelector('.about-gist') || {}).textContent || '',
    bodyShown: vis(d.querySelector('.doc-body.about')),
    more: getComputedStyle(d.querySelector('.about-more'), '::after').content,
  };
});
let f = await fold();
check(!f.open && !f.bodyShown, 'an activity description starts folded');
check('About this activity' === f.label, 'under a label that says what it is');
check(f.gistShown && /^Sound Lines builds the link between a sound and its spelling\.$/.test(f.gist),
  'showing its first sentence, got ' + JSON.stringify(f.gist));
check('"Read more"' === f.more, 'and offering the rest, got ' + f.more);

await p.click('details.about-fold >> nth=0 >> summary');
f = await fold();
check(f.open && f.bodyShown, 'clicking it shows the whole description');
check(!f.gistShown, 'without repeating the first sentence above it');
check('"Show less"' === f.more, 'and offers to close it again, got ' + f.more);
check((await p.evaluate(() => document.querySelectorAll('details.about-fold')[0].innerText)).includes('most of the early sequence is built on'),
  'all of it, not the second sentence only');
await p.click('details.about-fold >> nth=0 >> summary');
check(!(await fold()).open, 'and closing it folds it back');

/* A description that opens with headings has no first sentence to show. */
const kat = await p.evaluate(() => {
  const d = document.querySelectorAll('details.about-fold')[1];
  return { open: d.open, gist: !!d.querySelector('.about-gist'), summary: d.querySelector('summary').innerText.replace(/\s+/g, ' ').trim() };
});
check(!kat.open, 'a description that starts with a heading folds too');
check(!kat.gist && /^About this activity/.test(kat.summary), 'showing its label rather than headings run together, got ' + JSON.stringify(kat.summary));

/* Tutor notes are set apart from the description, under their own heading. */
const note = await p.evaluate(() => {
  const blk = document.querySelectorAll('.bf-report > .blk')[1];
  const n = blk.querySelector('.act-note');
  return n ? { head: n.querySelector('.act-note-h').textContent.trim(), text: n.innerText,
    outside: !n.closest('details'), border: getComputedStyle(n).borderLeftStyle } : null;
});
check(note && 'Tutor activity notes' === note.head, 'the tutor\'s notes on an activity are headed Tutor activity notes');
check(note && /completed the problem and the solution/.test(note.text), 'and hold what the tutor wrote');
check(note && note.outside, 'outside the folded description, so they show while it is closed');
check(note && 'solid' === note.border, 'set off with a rule');
check(0 === (await p.$$('.bf-report > .blk:first-child .act-note')).length, 'and an activity with no notes has no empty notes panel');

/* ---- the lightbox ---- */
check(0 === (await p.$$('.bftd-shotbox')).length, 'no dialog until somebody asks for one');

await p.click('a.shot-open');
await p.waitForTimeout(150);
const box = await p.evaluate(() => {
  const d = document.querySelector('.bftd-shotbox');
  if (!d) return null;
  const img = d.querySelector('img');
  const cs = getComputedStyle(d);
  return {
    src: img ? img.getAttribute('src') : '',
    alt: img ? img.getAttribute('alt') : '',
    label: d.getAttribute('aria-label'),
    modal: d.getAttribute('aria-modal'),
    position: cs.position,
    focusOnClose: document.activeElement === d.querySelector('.bftd-shotbox-x'),
  };
});
check(null !== box, 'clicking the work opens a dialog');
check(box && 'full-one.png' === box.src, 'showing the full size rather than the thumbnail');
check(box && "Kaine's spelling page" === box.alt, 'keeping the description of the work');
check(box && 'true' === box.modal, 'as a modal dialog');
check(box && "Kaine's spelling page" === box.label, 'named, so a screen reader says what opened');
check(box && 'fixed' === box.position, 'covering the page rather than sitting in the flow');
check(box && box.focusOnClose, 'with focus moved into it');

await p.keyboard.press('Escape');
await p.waitForTimeout(120);
check(0 === (await p.$$('.bftd-shotbox')).length, 'Escape closes it');
check(await p.evaluate(() => document.activeElement === document.querySelector('a.shot-open')),
  'and focus comes back to the picture that opened it');

/* The backdrop closes it; the picture does not. */
await p.click('a.shot-open');
await p.waitForTimeout(120);
await p.evaluate(() => document.querySelector('.bftd-shotbox img').click());
await p.waitForTimeout(120);
check(1 === (await p.$$('.bftd-shotbox')).length, 'clicking the picture itself does not close it');
await p.evaluate(() => {
  const d = document.querySelector('.bftd-shotbox');
  d.dispatchEvent(new MouseEvent('click', { bubbles: true }));
});
await p.waitForTimeout(120);
check(0 === (await p.$$('.bftd-shotbox')).length, 'but clicking the backdrop does');

await b.close();
console.log(fail ? '\n' + fail + ' failure(s)' : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
