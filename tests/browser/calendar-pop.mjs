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
  /* The tutor's colour (Karl, 1.212.1): this tutor is Green, on a draft booking. */
  const tc = await p.evaluate(() => {
    const pop = document.querySelector('.bftd-calv-pop');
    return { stripe: getComputedStyle(pop.querySelector('.bftd-calv-pop-head')).boxShadow,
      swatch: (pop.querySelector('.bftd-calv-pop-who .bftd-calv-swatch') && getComputedStyle(pop.querySelector('.bftd-calv-pop-who .bftd-calv-swatch')).backgroundColor) || '',
      edge: getComputedStyle(document.querySelector('.bftd-calv-ev.is-booked')).borderLeftColor };
  });
  check(/rgb\(92, 122, 41\)/.test(tc.stripe) && tc.swatch === 'rgb(92, 122, 41)' && tc.edge === 'rgb(92, 122, 41)',
    'the card\'s stripe and the swatch by the tutor are the tutor\'s colour, as on the booking, got ' + JSON.stringify(tc));

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
    const foot = await p.evaluate(() => [...document.querySelectorAll('.bftd-calv-pop-foot a')].map(x => x.textContent.trim() + ' ' + x.getAttribute('href')));
    check(foot.some(t => /^Record the session/.test(t) && /#record-501$/.test(t)), 'a booking with no session offers to record one, already linked (1.213), got ' + JSON.stringify(foot));
    await p.selectOption('.bftd-calv-attend select[name=status]', 'missed');
    check(!(await p.evaluate(() => document.querySelector('.bftd-calv-attend-missed').hidden)), 'choosing Missed asks who cancelled and why');
    await p.selectOption('.bftd-calv-attend select[name=missed_by]', '31');
    await p.fill('.bftd-calv-attend textarea[name=missed_why]', 'Family away');
    await p.evaluate(() => { window.__posts = []; });
    await p.click('.bftd-calv-attend button[type=submit]'); await p.waitForTimeout(200);
    const sent = await p.evaluate(() => window.__posts.find(b => b.action === 'bftd_calendar_attend'));
    check(!!sent && sent.status === 'missed' && sent.missed_by === '31' && sent.missed_why === 'Family away' && sent.student_id === '501' && sent.key === 'k1',
      'Save attendance sends the student, date, appointment and answer, got ' + JSON.stringify(sent));
    check(!(await shown()).pop, 'and the card closes once it is saved');
    await p.click('.bftd-calv-ev.is-held'); await p.waitForTimeout(300);
    check(!(await p.evaluate(() => document.querySelector('.bftd-calv-pop .bftd-calv-attend'))), 'a booking someone cannot mark shows no attendance choice');
    await p.keyboard.press('Escape');

    /* All (Karl, 1.212): ticks or unticks every filter. */
    console.log('\nThe All filter:');
    const ticks = () => p.evaluate(() => ({
      all: document.querySelector('.bftd-calv-showall').checked,
      part: document.querySelector('.bftd-calv-showall').indeterminate,
      on: ['showghl', 'showdraft', 'showheld', 'showattended', 'shownoshow', 'showcancel', 'showmoved']
        .filter(c => document.querySelector('.bftd-calv-' + c).checked).length,
      shown: document.querySelectorAll('.bftd-calv-ev').length,
    }));
    let t = await ticks();
    check(!t.all && t.part && t.on === 5, 'with some filters on, All shows part-ticked, got ' + JSON.stringify(t));
    await p.click('.bftd-calv-showall'); await p.waitForTimeout(100);
    t = await ticks();
    check(t.all && !t.part && t.on === 7, 'ticking All ticks every filter, got ' + JSON.stringify(t));
    await p.click('.bftd-calv-showall'); await p.waitForTimeout(100);
    t = await ticks();
    check(!t.all && t.on === 0 && t.shown === 0, 'unticking it unticks them all, got ' + JSON.stringify(t));
    await p.click('.bftd-calv-showall'); await p.waitForTimeout(100);
    await p.click('.bftd-calv-showcancel'); await p.waitForTimeout(100);
    t = await ticks();
    check(!t.all && t.part && t.on === 6 && t.shown === 3, 'and unticking one leaves All part-ticked, got ' + JSON.stringify(t));
    await p.click('.bftd-calv-showcancel'); await p.waitForTimeout(100);

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
    /* And by the school a student is linked to (Karl, 1.212.2). */
    await p.fill('.bftd-calv-search', 'prairie sky'); await p.waitForTimeout(100);
    check(1 === await all() && 1 === (await count()).noshow, 'a linked school\'s name shows its students\' bookings');
    check(/schools/.test(await p.getAttribute('.bftd-calv-search', 'placeholder')), 'and the box says it searches schools too');
    await p.fill('.bftd-calv-search', ''); await p.waitForTimeout(100);
    check(3 === await all(), 'and clearing it brings them all back');

    /* Each ticked tutor's students, all ticked to start (Karl, 1.212). */
    console.log('\nEach tutor\'s students:');
    const kids = () => p.evaluate(() => [...document.querySelectorAll('.bftd-calv-kids')].map(k => ({
      t: k.dataset.t, names: [...k.querySelectorAll('.bftd-calv-kid')].map(l => l.textContent.trim()),
      on: [...k.querySelectorAll('input')].filter(c => c.checked).length })));
    let k = await kids();
    check(k.length === 2 && k[0].t === '1' && k[0].names.join('|') === 'Pat Example|Sam Other' && k[0].on === 2 && k[1].names.join('|') === 'Robin Third',
      'under every ticked tutor, their students (assigned, or booked with them), all ticked, got ' + JSON.stringify(k));
    await p.locator('.bftd-calv-kids[data-t="1"] .bftd-calv-kid', { hasText: 'Pat Example' }).locator('input').click(); await p.waitForTimeout(100);
    check(1 === await all(), 'unticking a student hides their bookings');
    await p.click('.bftd-calv-tutor input[value="2"]'); await p.waitForTimeout(200);
    k = await kids();
    check(k.length === 1 && k[0].t === '1' && k[0].on === 1 && 1 === await all(), 'unticking a tutor drops their list and keeps the others as they were');
    await p.click('.bftd-calv-tutor input[value="1"]'); await p.waitForTimeout(200);
    check(0 === (await kids()).length, 'with no tutor ticked there is no list');
    await p.click('.bftd-calv-tutor input[value="1"]'); await p.click('.bftd-calv-tutor input[value="2"]'); await p.waitForTimeout(250);
    k = await kids();
    check(k[0].on === 2 && 3 === await all(), 'ticking a tutor again starts with all their students ticked');
    await p.locator('.bftd-calv-kids[data-t="1"] [data-k="none"]').click(); await p.waitForTimeout(100);
    check(0 === await all() && 0 === (await kids())[0].on, 'None unticks all of a tutor\'s students');
    await p.locator('.bftd-calv-kids[data-t="1"] [data-k="all"]').click(); await p.waitForTimeout(100);
    check(3 === await all(), 'and All ticks them again');

    /* Every day square is white unless closed (Karl, 1.212). */
    console.log('\nDay squares:');
    const days = (sel) => p.evaluate((sel) => [...document.querySelectorAll(sel)].map(c => ({
      closed: c.classList.contains('is-closed'), today: c.classList.contains('is-today'), other: c.classList.contains('is-other'),
      bg: getComputedStyle(c).backgroundColor })), sel);
    let d = await days('.bftd-calv-col');
    check(d.length === 7 && d.filter(x => !x.closed).every(x => x.bg === 'rgb(255, 255, 255)'), 'in the week, every open day is white, today too, got ' + JSON.stringify([...new Set(d.filter(x => !x.closed).map(x => x.bg))]));
    await p.selectOption('.bftd-calv-view', 'month'); await p.waitForTimeout(250);
    d = await days('.bftd-calv-cell');
    check(d.length === 42 && d.some(x => x.other) && d.filter(x => !x.closed).every(x => x.bg === 'rgb(255, 255, 255)'),
      'in the month, every open day is white, other months\' days too, got ' + JSON.stringify([...new Set(d.filter(x => !x.closed).map(x => x.bg))]));
    const mark = await p.evaluate(() => { const b = document.querySelector('.bftd-calv-cell.is-today > .bftd-calv-goday'); return b && getComputedStyle(b).backgroundColor; });
    check(mark === 'rgb(112, 86, 127)', 'today is marked on its date number instead, got ' + mark);
    await p.selectOption('.bftd-calv-view', 'week'); await p.waitForTimeout(200);
  }
  await p.close();
}

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
