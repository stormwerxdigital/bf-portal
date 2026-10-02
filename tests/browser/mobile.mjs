/*
 * Every screen, at the width people actually hold.
 *
 * A parent opens a progress report on a phone, in a car park, five minutes
 * before they collect their child. A tutor writes a session up on a tablet
 * between two lessons. Neither of them has the window this was designed in.
 *
 * The failures that matter here are the ones nobody sees on a laptop and
 * nobody can work around on a phone:
 *
 *   the page scrolls sideways, so half of every line is off the screen and
 *   reading means dragging;
 *
 *   something is wider than the viewport, which is what causes that;
 *
 *   a control is too small to hit with a thumb, so the person taps three
 *   times and gives up;
 *
 *   text is too small to read at arm's length.
 *
 * All four are measurable, so they are measured rather than eyeballed, on
 * every fixture the suite already builds.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { existsSync } from 'node:fs';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));

/* Every screen, and the builder that makes it. Built fresh, so the audit is
   of the code as it is now rather than of an html file left over from a run
   three versions ago. */
const SCREENS = [
  ['the family progress report', 'build-report.php',        'report.html'],
  ['a session record',           'build-session-screen.php','session-screen-moved.html'],
  ['the session editor',         'build-session-screen.php','session-screen.html'],
  ['the students screen',        'build-students.php',      'students.html'],
  ['the sessions screen',        'build-sessions.php',      'sessions.html'],
  ['the oversight report',       'build-oversight.php',     'oversight.html'],
  ['the reading charts',         'build-report-tabs.php',   'report-tabs.html'],
  ['the skills list',            'build-skill-rows.php',    'skill-rows.html'],
  ['an activity row',            'build-lesson-row.php',    'lesson-row.html'],
  ['the lesson records',         'build-lessons.php',       'lessons.html'],
  ['the time cards',             'build-timecards.php',     'timecards.html'],
  ['a tutor\'s own time card',   'build-timecards.php',     'timecard-mine.html'],
  ['the worked example',         'build-timecards.php',     'timecard-sample.html'],
  ['a statutory holiday',        'build-timecards.php',     'timecard-stat.html'],
  ['the people pickers',         'build-client-pick.php',   'client-pick.html'],
  ['the library pickers',        'build-picker.php',        'picker.html'],
  ['a folded description and work', 'build-portal-bits.php', 'portal-bits.html'],
];

/* Two real phones. The narrow one is not a corner case: it is the width of
   an iPhone SE and of a Galaxy in a split view, and it is where a layout
   that only just fits stops fitting. */
const WIDTHS = [
  ['a phone', 390, 844],
  ['a small phone', 360, 740],
];

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

for (const [name, builder, file] of SCREENS) {
  try { execFileSync('php', [join(here, builder)], { stdio: 'pipe' }); } catch (e) { /* builder prints its own path */ }
  const path = join(here, file);
  if (!existsSync(path)) { check(false, `${name}: fixture ${file} was not built`); continue; }

  for (const [phone, w, h] of WIDTHS) {
    const p = await browser.newPage({ viewport: { width: w, height: h }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    await p.goto('file://' + path);
    await p.waitForTimeout(200);

    /* Everything a person can open, opened.
     *
     * A closed panel measures as nothing: no width to overflow with, no tap
     * target, no text to be too small. Half this report is behind a chevron,
     * so an audit that only reads what is open on load has not read the
     * report. */
    await p.evaluate(() => {
      document.querySelectorAll('[aria-expanded="false"]').forEach(b => { try { b.click(); } catch (e) {} });
      document.querySelectorAll('details:not([open])').forEach(d => { d.open = true; });
    });
    await p.waitForTimeout(350);

    const found = await p.evaluate((vw) => {
      const out = { scroll: 0, wide: [], small: [], tiny: [], hover: [], chart: [], tabs: [], collide: [] };

      out.scroll = Math.max(0, document.documentElement.scrollWidth - document.documentElement.clientWidth);

      /* What is actually sticking out. An element inside something that
         scrolls on purpose — a table a person can swipe — is not a fault, so
         anything under an overflow-x container is left alone. */
      const scrolly = (el) => {
        for (let n = el.parentElement; n; n = n.parentElement) {
          const ox = getComputedStyle(n).overflowX;
          if ('auto' === ox || 'scroll' === ox) return true;
        }
        return false;
      };

      document.querySelectorAll('body *').forEach(el => {
        const cs = getComputedStyle(el);
        if ('none' === cs.display || 'hidden' === cs.visibility || el.offsetParent === null) return;
        const r = el.getBoundingClientRect();
        if (r.width === 0 && r.height === 0) return;

        if (r.right > vw + 1 && !scrolly(el)) {
          out.wide.push({
            tag: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : ''),
            right: Math.round(r.right),
          });
        }

        /* Anything a thumb is meant to hit. 40px is below Apple's 44 and
           Google's 48, and is the point where a miss stops being the
           person's fault.
           
           Only the controls THIS PLUGIN styles. A bare <select> on an admin
           screen is sized by WordPress, which gives it 40px of its own under
           782px; measuring it here without wp-admin's stylesheet loaded
           would have me "fixing" WordPress in a fixture. The portal is the
           other way round — there is no WordPress CSS there at all, so
           everything inside .bf-report is ours.
           
           A control hidden for the accessibility tree and replaced by a
           visible one — the multi-select under the chips — is not a target
           anybody aims at, and is skipped rather than reported as a 1px
           button. */
        const ours = el.closest('.bf-report') || (el.className && typeof el.className === 'string' && /\bbftd-/.test(el.className));
        const clipped = cs.clipPath === 'inset(50%)' || cs.clip === 'rect(0px, 0px, 0px, 0px)' || (r.width <= 2 && r.height <= 2);
        const tappable = el.matches('a[href], button, input[type="submit"], input[type="button"], select, [role="button"], [role="tab"], summary');
        if (tappable && ours && !clipped && (r.height < 40 || r.width < 24)) {
          out.small.push({ what: (el.textContent || '').trim().slice(0, 24) || el.tagName, h: Math.round(r.height), w: Math.round(r.width) });
        }

        /* Text below 12px is not read on a phone, it is squinted at. Icons
           and things with no words are not text. */
        const txt = [...el.childNodes].some(n => 3 === n.nodeType && n.textContent.trim().length > 1);
        const mine = el.closest('.bf-report') || (el.className && typeof el.className === 'string' && /\bbftd-/.test(el.className))
          || (el.parentElement && el.parentElement.className && typeof el.parentElement.className === 'string' && /\bbftd-/.test(el.parentElement.className));
        if (txt && mine && parseFloat(cs.fontSize) < 12) {
          out.tiny.push({ px: parseFloat(cs.fontSize), what: el.textContent.trim().slice(0, 24) });
        }
      });

      /* Text inside a chart, at the size it actually comes out.
       *
       * An SVG with a 720-wide viewBox scaled into a 300px column renders
       * every label at forty per cent of its stated size: 10.5px becomes
       * four. The checks above skip all of it, because an SVG element has no
       * offsetParent — which is exactly why a chart can be illegible on a
       * phone and an audit can call the page clean. */
      document.querySelectorAll('svg text').forEach(el => {
        const svg = el.ownerSVGElement;
        if (!svg || !svg.getBoundingClientRect().width) return;
        const vb = svg.viewBox && svg.viewBox.baseVal;
        const scale = vb && vb.width ? svg.getBoundingClientRect().width / vb.width : 1;
        const shown = parseFloat(getComputedStyle(el).fontSize) * scale;
        if (shown < 11.5) out.chart.push({ px: Math.round(shown * 10) / 10, what: el.textContent.trim().slice(0, 18) });
      });

      /* And that making it readable has not made it collide.
       *
       * Chart labels share a fixed space between them. Every point of font
       * size spends some of it, so "big enough to read" and "not on top of
       * each other" pull against one another and the only way to pick a
       * number is to measure both. Text over text is the one thing a parent
       * cannot read past. */
      document.querySelectorAll('svg.chart').forEach(svg => {
        if (!svg.getBoundingClientRect().width) return;
        /* Screen space, not the SVG's own. getBBox() answers in user units
         * and ignores every transform between the text and the page, so a
         * label the chart has moved out of the way still reads as sitting
         * where it was written — which is an overlap the eye never sees. */
        const boxes = [...svg.querySelectorAll('text')].map(t => {
          const b = t.getBoundingClientRect();
          return { s: t.textContent.trim(), x: b.x, y: b.y, w: b.width, h: b.height };
        });
        for (let i = 0; i < boxes.length; i++) {
          for (let k = i + 1; k < boxes.length; k++) {
            const a = boxes[i], c = boxes[k];
            if (a.x < c.x + c.w && c.x < a.x + a.w && a.y < c.y + c.h && c.y < a.y + a.h) {
              out.collide.push(a.s + ' / ' + c.s);
            }
          }
        }
      });

      /* A tab strip that has wrapped.
       *
       * The track is a capsule with a 999px radius. Wrapped, it becomes a
       * two-row capsule with one tab sitting under the other and the shape
       * broken around them — which nothing else here can see, because a
       * wrapped strip does not overflow and every button is still the right
       * size. What gives it away is the buttons no longer being on one line. */
      document.querySelectorAll('[role="tablist"], .tabstrip').forEach(strip => {
        const tabs = [...strip.querySelectorAll('button, [role="tab"]')];
        if (tabs.length < 2) return;
        const tops = new Set(tabs.map(t => Math.round(t.getBoundingClientRect().top)));
        const full = tabs.every(t => t.getBoundingClientRect().width >= strip.getBoundingClientRect().width - 12);
        // Wrapped is fine when each tab takes the full width: that is a
        // stack, on purpose. Wrapped into ragged rows is the broken capsule.
        if (tops.size > 1 && !full) out.tabs.push(tabs.map(t => t.textContent.trim().slice(0, 18)));
      });

      /* Nothing a person needs may be behind a hover.
       *
       * A touch screen has no hover. A row action revealed by the pointer is
       * revealed by nothing at all on a phone, and the link is not missing —
       * it is there, sized, in the accessibility tree, and permanently
       * invisible. Skipping hidden elements, as the checks above do, is
       * exactly how that failure hides from an audit. */
      document.querySelectorAll('a[href], button').forEach(el => {
        // Genuinely closed panels are fine: they have a control that opens
        // them. What is not fine is a link with no way to reach it.
        if (el.closest('[hidden]') || el.closest('.acc') || el.closest('details:not([open])')) return;

        /* The ancestors, always. Opacity does not inherit as a computed
           value, so a link inside a faded-out container reports its own
           opacity as 1 — and an earlier version of this check read that and
           returned, which is how it passed while the row actions were
           invisible. */
        let hidden = false;
        for (let n = el; n && n !== document.body; n = n.parentElement) {
          const p = getComputedStyle(n);
          if (parseFloat(p.opacity) <= 0.01 || 'hidden' === p.visibility) { hidden = true; break; }
        }
        if (hidden) out.hover.push((el.textContent || '').trim().slice(0, 24) || el.tagName);
      });

      const seen = new Set();
      out.wide = out.wide.filter(x => !seen.has(x.tag) && seen.add(x.tag)).slice(0, 6);
      const s2 = new Set();
      out.small = out.small.filter(x => !s2.has(x.what) && s2.add(x.what)).slice(0, 6);
      const s3 = new Set();
      out.tiny = out.tiny.filter(x => !s3.has(x.px + x.what) && s3.add(x.px + x.what)).slice(0, 6);
      const s4 = new Set();
      out.hover = out.hover.filter(x => !s4.has(x) && s4.add(x)).slice(0, 6);
      const s5 = new Set();
      out.chart = out.chart.filter(x => !s5.has(x.px + x.what) && s5.add(x.px + x.what)).slice(0, 6);
      const s6 = new Set();
      out.collide = out.collide.filter(x => !s6.has(x) && s6.add(x)).slice(0, 6);
      return out;
    }, w);

    const where = `${name} on ${phone}`;
    check(found.scroll === 0, `${where}: does not scroll sideways${found.scroll ? ', over by ' + found.scroll + 'px' : ''}`);
    check(found.wide.length === 0, `${where}: nothing is wider than the screen${found.wide.length ? ' — ' + JSON.stringify(found.wide) : ''}`);
    check(found.small.length === 0, `${where}: every control is big enough for a thumb${found.small.length ? ' — ' + JSON.stringify(found.small) : ''}`);
    check(found.tiny.length === 0, `${where}: nothing is set below 12px${found.tiny.length ? ' — ' + JSON.stringify(found.tiny) : ''}`);
    check(found.hover.length === 0, `${where}: nothing useful is behind a hover${found.hover.length ? ' — ' + JSON.stringify(found.hover) : ''}`);
    check(found.chart.length === 0, `${where}: chart labels come out big enough to read${found.chart.length ? ' — ' + JSON.stringify(found.chart) : ''}`);
    check(found.collide.length === 0, `${where}: and no two of them sit on top of each other${found.collide.length ? ' — ' + JSON.stringify(found.collide) : ''}`);
    check(found.tabs.length === 0, `${where}: no tab strip is wrapped into a broken capsule${found.tabs.length ? ' — ' + JSON.stringify(found.tabs) : ''}`);

    await p.close();
  }
}

await browser.close();
console.log(fail ? `\n${fail} to fix` : '\nall clear');
process.exit(fail ? 1 : 0);
