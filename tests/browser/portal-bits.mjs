/*
 * The folded description and the work lightbox, driven in a browser.
 *
 * Both ship open and working with no script at all, so the thing that can break
 * silently is the upgrade. A fold that never folds looks like a long page; a
 * lightbox that never opens looks like an ordinary link. Neither shows up in a
 * unit test and neither throws.
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

/* ---- the fold ---- */
const shut = await p.evaluate(() => ({
  text: document.querySelector('.doc-body.about').innerText.trim(),
  btn: (document.querySelector('.about-more') || {}).textContent || '',
  expanded: (document.querySelector('.about-more') || {}).getAttribute
    ? document.querySelector('.about-more').getAttribute('aria-expanded') : null,
}));
check(/^Sound Lines builds the link between a sound and its spelling\.$/.test(shut.text),
  'the description folds to its first sentence');
check('Read more' === shut.btn, 'with a button offering the rest');
check('false' === shut.expanded, 'which says it is closed');

await p.click('.about-more');
const open = await p.evaluate(() => ({
  text: document.querySelector('.doc-body.about').innerText.trim(),
  btn: document.querySelector('.about-more').textContent,
  expanded: document.querySelector('.about-more').getAttribute('aria-expanded'),
}));
check(open.text.includes('writes each sound as they say it'), 'clicking it shows the whole description');
check(open.text.includes('most of the early sequence is built on'), 'all of it, not the second sentence only');
check('Show less' === open.btn, 'and the button offers to close it again');
check('true' === open.expanded, 'which says it is open');

await p.click('.about-more');
check(/^Sound Lines builds the link between a sound and its spelling\.$/.test(
  await p.evaluate(() => document.querySelector('.doc-body.about').innerText.trim())),
  'and closing it folds it back');

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
