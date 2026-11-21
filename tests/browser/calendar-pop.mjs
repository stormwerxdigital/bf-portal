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

  const open = async () => { await p.click('.bftd-calv-ev.is-booked'); await p.waitForTimeout(300); };
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
      gap: del.top - save.bottom, focus: document.activeElement === pop, delText: pop.querySelector(".bftd-calv-delete").textContent.trim(),
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
  check(m.delText === 'Delete', 'the delete link just says Delete, got ' + m.delText);
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

  if (name === 'desktop') {
    /* Attended and No shows (Karl, 1.210): each its own filter, and a no-show
       looks and reads as one. */
    console.log('\nAttended and No shows on the Calendar:');
    const count = () => p.evaluate(() => ({
      booked: document.querySelectorAll('.bftd-calv-ev.is-booked').length,
      held: document.querySelectorAll('.bftd-calv-ev.is-held').length,
      noshow: document.querySelectorAll('.bftd-calv-ev.is-noshow').length,
    }));
    let c = await count();
    check(c.booked === 1 && c.held === 1 && c.noshow === 1, 'all three show to begin with, got ' + JSON.stringify(c));
    const ns = await p.evaluate(() => {
      const b = document.querySelector('.bftd-calv-ev.is-noshow');
      return { label: b.getAttribute('aria-label'), bg: getComputedStyle(b).backgroundColor,
        key: [...document.querySelectorAll('.bftd-calv-k')].map(k => k.textContent.trim()) };
    });
    check(/no show/.test(ns.label) && ns.bg === 'rgb(250, 237, 227)', 'a no-show reads "no show" and is clay, got ' + ns.bg);
    check(ns.key.includes('Attended') && ns.key.includes('No show'), 'and the key names Attended and No show');
    await p.click('.bftd-calv-showattended'); await p.waitForTimeout(100);
    c = await count();
    check(c.held === 0 && c.noshow === 1 && c.booked === 1, 'unticking Attended hides only the attended booking, got ' + JSON.stringify(c));
    await p.click('.bftd-calv-shownoshow'); await p.waitForTimeout(100);
    c = await count();
    check(c.noshow === 0 && c.booked === 1, 'unticking No shows hides the no-show, got ' + JSON.stringify(c));
    await p.click('.bftd-calv-showattended'); await p.click('.bftd-calv-shownoshow'); await p.waitForTimeout(100);
    c = await count();
    check(c.held === 1 && c.noshow === 1, 'and ticking them brings both back');

    /* Marking attendance from the card (Karl, 1.211). */
    console.log('\nMarking attendance:');
    await open();
    const a = await p.evaluate(() => {
      const f = document.querySelector('.bftd-calv-pop .bftd-calv-attend');
      return f && { opts: [...f.elements.status.options].map(o => o.textContent), missedHidden: f.querySelector('.bftd-calv-attend-missed').hidden,
        where: f.querySelector('.bftd-calv-attend-where').textContent,
        before: !!(f.compareDocumentPosition(document.querySelector('.bftd-calv-pop .bftd-calv-edit')) & Node.DOCUMENT_POSITION_FOLLOWING) };
    });
    check(!!a && a.opts.join('|') === 'Not marked|Attended|Late start|No show|Missed', 'a booking from today back can be marked, got ' + JSON.stringify(a && a.opts));
    check(!!a && a.missedHidden && /No session yet/.test(a.where), 'Cancelled by and Why stay hidden, and it says the mark waits for the session');
    check(!!a && a.before, 'attendance comes before the booking\'s own changes');
    await p.selectOption('.bftd-calv-attend select[name=status]', 'missed');
    check(!(await p.evaluate(() => document.querySelector('.bftd-calv-attend-missed').hidden)), 'choosing Missed asks who cancelled and why');
    await p.selectOption('.bftd-calv-attend select[name=missed_by]', '31');
    await p.fill('.bftd-calv-attend textarea[name=missed_why]', 'Family away');
    await p.evaluate(() => { window.__posts = []; });
    await p.click('.bftd-calv-attend button[type=submit]'); await p.waitForTimeout(200);
    const sent = await p.evaluate(() => window.__posts.find(b => b.action === 'bftd_calendar_attend'));
    check(!!sent && sent.status === 'missed' && sent.missed_by === '31' && sent.missed_why === 'Family away' && sent.student_id === '501',
      'Save attendance sends the student, date and answer, got ' + JSON.stringify(sent));
    check(!(await shown()).pop, 'and the card closes once it is saved');
    await p.click('.bftd-calv-ev.is-held'); await p.waitForTimeout(300);
    check(!(await p.evaluate(() => document.querySelector('.bftd-calv-pop .bftd-calv-attend'))), 'a booking someone cannot mark shows no attendance choice');
    await p.keyboard.press('Escape');

    /* Search by student name (Karl, 1.210). */
    console.log('\nSearching by student:');
    const all = () => p.evaluate(() => document.querySelectorAll('.bftd-calv-ev').length);
    const box = await p.evaluate(() => { const s = document.querySelector('.bftd-calv-search');
      return s && { type: s.type, label: !!document.querySelector('label[for="' + s.id + '"]'), ph: s.placeholder }; });
    check(!!box && box.type === 'search' && box.label, 'there is a labelled search box, got ' + JSON.stringify(box));
    await p.fill('.bftd-calv-search', 'sam'); await p.waitForTimeout(100);
    check(1 === await all() && 1 === (await count()).noshow, 'typing part of a name shows only that student\'s bookings');
    await p.fill('.bftd-calv-search', 'PAT EX'); await p.waitForTimeout(100);
    check(2 === await all(), 'any case, and more than one word');
    await p.fill('.bftd-calv-search', 'nobody'); await p.waitForTimeout(100);
    check(0 === await all(), 'a name with no bookings shows none');
    await p.fill('.bftd-calv-search', ''); await p.waitForTimeout(100);
    check(3 === await all(), 'and clearing it brings them all back');
  }
  await p.close();
}

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
