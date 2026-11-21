/*
 * A booking on the Calendar opens as a card in the middle of the screen, over
 * a dimmed and blurred calendar, with a close X at its top right (Karl,
 * 1.209). The X, the veil and Escape each close it, and focus goes back to the
 * booking that was tapped. Save changes has room between it and the links
 * beneath. Checked on a desktop and a phone.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-calendar-pop.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);

for (const [name, vp] of [['desktop', { width: 1340, height: 900 }], ['phone', { width: 375, height: 812 }]]) {
  console.log(`\nOn a ${name}:`);
  const p = await browser.newPage({ viewport: vp });
  await p.goto('file://' + page_path);
  await p.waitForSelector('.bftd-calv-ev');

  const open = async () => { await p.click('.bftd-calv-ev'); await p.waitForTimeout(300); };
  const shown = () => p.evaluate(() => ({
    pop: !document.querySelector('.bftd-calv-pop').hidden,
    veil: !document.querySelector('.bftd-calv-veil').hidden,
  }));

  const widthBefore = await p.evaluate(() => document.documentElement.scrollWidth);
  await open();
  const m = await p.evaluate(() => {
    const pop = document.querySelector('.bftd-calv-pop'), veil = document.querySelector('.bftd-calv-veil');
    const r = pop.getBoundingClientRect(), x = pop.querySelector('.bftd-calv-x');
    const xr = x ? x.getBoundingClientRect() : null;
    const save = pop.querySelector('button[type=submit]').getBoundingClientRect();
    const del = pop.querySelector('.bftd-calv-delete').getBoundingClientRect();
    const vs = getComputedStyle(veil);
    return {
      fixed: getComputedStyle(pop).position, r: { l: r.left, t: r.top, w: r.width, h: r.height, rt: r.right, b: r.bottom },
      vw: innerWidth, vh: innerHeight, scrollW: document.documentElement.scrollWidth,
      blur: vs.backdropFilter || vs.webkitBackdropFilter, veilBg: vs.backgroundColor,
      veilCovers: (() => { const v = veil.getBoundingClientRect(); return v.left <= 0 && v.top <= 0 && v.right >= innerWidth && v.bottom >= innerHeight; })(),
      veilUnder: Number(vs.zIndex) < Number(getComputedStyle(pop).zIndex),
      x: xr && { label: x.getAttribute('aria-label'), dr: r.right - xr.right, dt: xr.top - r.top, w: xr.width },
      gap: del.top - save.bottom, focus: document.activeElement === pop,
      modal: pop.getAttribute('aria-modal'),
    };
  });
  const sh = await shown();
  check(sh.pop && sh.veil, 'tapping a booking shows the card and the veil');
  check(m.fixed === 'fixed', 'the card sits on the screen, not the page, got ' + m.fixed);
  check(Math.abs(m.r.l + m.r.w / 2 - m.vw / 2) < 2 && Math.abs(m.r.t + m.r.h / 2 - m.vh / 2) < 2,
    'in the middle of the screen, got ' + JSON.stringify(m.r));
  check(m.r.l >= 8 && m.r.t >= 8 && m.r.rt <= m.vw - 8 && m.r.b <= m.vh - 8, 'and all of it fits');
  check(m.scrollW <= widthBefore, 'and it makes the page no wider, got ' + m.scrollW + ' against ' + widthBefore);
  check(/blur\(\s*\d/.test(m.blur || ''), 'the calendar behind is blurred, got ' + m.blur);
  check(/rgba\(.*0\.\d+\)/.test(m.veilBg) && m.veilCovers && m.veilUnder, 'under a see-through veil covering the screen, got ' + m.veilBg);
  check(!!m.x && m.x.label === 'Close' && m.x.dr >= 0 && m.x.dr <= 24 && m.x.dt >= 0 && m.x.dt <= 24 && m.x.w >= 32,
    'a close X at the top right, big enough to tap, got ' + JSON.stringify(m.x));
  check(m.gap >= 14, 'room between Save changes and the links beneath, got ' + Math.round(m.gap) + 'px');
  check(m.focus && m.modal === 'true', 'the card takes focus and is a modal dialog');

  await p.click('.bftd-calv-x'); await p.waitForTimeout(100);
  let s = await shown();
  const back = await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('bftd-calv-ev'));
  check(!s.pop && !s.veil, 'the X closes it');
  check(back, 'and focus goes back to the booking');

  await open();
  await p.mouse.click(4, vp.height - 4); await p.waitForTimeout(100);
  s = await shown();
  check(!s.pop && !s.veil, 'tapping the veil closes it');

  await open();
  await p.keyboard.press('Escape'); await p.waitForTimeout(100);
  s = await shown();
  check(!s.pop && !s.veil, 'and so does Escape');
  await p.close();
}

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
