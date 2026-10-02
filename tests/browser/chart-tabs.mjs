/*
 * Two charts in one card, behind tabs.
 *
 * The thing worth guarding is not the switching, it is what the page is
 * before the script runs. Both panels ship visible so a report with scripts
 * blocked, or on paper, is two charts down the page under two headings —
 * exactly what it was before the tabs existed. A tab strip rendered as the
 * default state would leave that reader one chart and a row of dead buttons,
 * and nothing in the source says which way round it is.
 *
 * So this loads the page twice: once with JavaScript turned off, once with it
 * on, and reads what is actually visible.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-report-tabs.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

/* ---- scripts blocked ---- */
const noJs = await browser.newContext({ javaScriptEnabled: false });
const p0 = await noJs.newPage();
await p0.goto('file://' + page_path);
const bare = await p0.evaluate(() => ({
  panels: [...document.querySelectorAll('.tabpanel')].filter(e => e.offsetParent !== null).length,
  heads: [...document.querySelectorAll('.tabpanel-h')].filter(e => e.offsetParent !== null).map(e => e.textContent.trim()),
  strip: document.querySelector('.tabstrip').offsetParent !== null,
  charts: [...document.querySelectorAll('.tabpanel svg.chart')].filter(e => e.getBoundingClientRect().height > 10).length,
}));
check(bare.panels === 2, 'with no script, both charts are on the page, got ' + bare.panels);
check(bare.charts === 2, 'and both are actually drawn, got ' + bare.charts);
check(bare.heads.length === 2, 'each under its own heading, got ' + JSON.stringify(bare.heads));
check(!bare.strip, 'and the buttons are not shown, because they would do nothing');
await noJs.close();

/* ---- scripts on ---- */
const p = await browser.newPage({ viewport: { width: 900, height: 900 } });
const errors = [];
p.on('pageerror', e => errors.push(String(e)));
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

const state = () => p.evaluate(() => ({
  strip: document.querySelector('.tabstrip').offsetParent !== null,
  shown: [...document.querySelectorAll('.tabpanel')].filter(e => !e.hidden).map(e => e.id),
  on: [...document.querySelectorAll('.tabbtn')].filter(e => e.getAttribute('aria-selected') === 'true').map(e => e.textContent.trim()),
  tabbable: [...document.querySelectorAll('.tabbtn')].filter(e => e.getAttribute('tabindex') !== '-1').length,
  heads: [...document.querySelectorAll('.tabpanel-h')].filter(e => e.getBoundingClientRect().width > 2).length,
}));

let s = await state();
check(s.strip, 'with the script in, the strip appears');
check(s.shown.length === 1 && s.shown[0] === 'panel-overview', 'one panel at a time, the first, got ' + JSON.stringify(s.shown));
check(s.on.length === 1 && s.on[0] === 'Reading level', 'and one tab reads as selected, got ' + JSON.stringify(s.on));
check(s.heads === 0, 'the panel headings give way, because the strip now says which is which');

/* One tab in the tab order, arrows between them: how a tablist works, and how
   somebody who cannot use a mouse gets to the second chart at all. */
check(s.tabbable === 1, 'only the selected tab is in the tab order, got ' + s.tabbable);

await p.click('.tabbtn:nth-of-type(2)');
await p.waitForTimeout(150);
s = await state();
check(s.shown.length === 1 && s.shown[0] === 'panel-wpm', 'a click swaps which chart is showing, got ' + JSON.stringify(s.shown));
check(s.on[0] === 'Words correct per minute', 'and which tab reads as selected');

const drawn = await p.evaluate(() => {
  const el = document.querySelector('#panel-wpm svg.chart');
  return el ? Math.round(el.getBoundingClientRect().height) : 0;
});
check(drawn > 100, 'the chart that was hidden draws at full height once shown, got ' + drawn);

/* The keyboard. A tab strip that only answers a mouse is a tab strip half the
   readers cannot get past. */
await p.focus('.tabbtn[aria-selected="true"]');
await p.keyboard.press('ArrowLeft');
await p.waitForTimeout(150);
s = await state();
check(s.shown[0] === 'panel-overview', 'the arrow keys move between them, got ' + JSON.stringify(s.shown));

/* The switch has to look like a switch.
 *
 * It shipped as a white pill on a near-white track with grey labels: three
 * shades of almost nothing, and a reader had no reason to believe the second
 * chart existed. Colour choices drift, so this reads the rendered pixels
 * rather than the stylesheet, in both themes. */
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

/* The pointer is still sitting on the tab it clicked, so anything measured
   now is the hover style. Move it off first: this test reported the unselected
   tab as purple, which is what hovering it looks like and not what it is. */
await p.mouse.move(0, 0);
await p.waitForTimeout(120);

for (const theme of ['light', 'dark']) {
  await p.evaluate(t => document.querySelector('.bf-report').setAttribute('data-theme', t), theme);
  await p.waitForTimeout(120);
  const seen = await p.evaluate(() => {
    const cs = e => getComputedStyle(e);
    const on = document.querySelector('.tabbtn.is-on');
    const off = document.querySelector('.tabbtn:not(.is-on)');
    return {
      onFg: cs(on).color, onBg: cs(on).backgroundColor,
      offFg: cs(off).color,
      strip: cs(document.querySelector('.tabstrip')).backgroundColor,
      // The report's own two text tones, read off the page rather than
      // written down here, so the test cannot drift from the palette.
      ink: cs(document.querySelector('.tabpanel-h')).color,
      muted: cs(document.querySelector('.tabpanel-l')).color,
    };
  });
  check(ratio(seen.onFg, seen.onBg) >= 4.5,
    `${theme}: the chosen tab's words carry on its fill, ${ratio(seen.onFg, seen.onBg).toFixed(2)}:1`);
  check(ratio(seen.onBg, seen.strip) >= 1.6,
    `${theme}: and the fill stands off the track it sits on, ${ratio(seen.onBg, seen.strip).toFixed(2)}:1`);
  /* Contrast alone does not settle this one: caption grey passes 4.5:1 and
     still reads as a label rather than a control. So the test is that the
     unselected tab is set in the same ink as a heading, not in the muted tone
     the report uses for captions — which is the difference between "here is
     what you are looking at" and "you can press this". */
  check(Math.abs(lum(seen.offFg) - lum(seen.ink)) < 0.01,
    `${theme}: the other tab is set in ink like a heading, not caption grey`);
  check(Math.abs(lum(seen.offFg) - lum(seen.muted)) > 0.05,
    `${theme}: and is plainly darker than the muted tone beside it`);
}
await p.evaluate(() => document.querySelector('.bf-report').setAttribute('data-theme', 'light'));

/* The milestone cards, once. They belong to the assessments rather than to
   either measure, and both charts are drawn from the same three — a set above
   each was the same three cards twice inside one card. */
const miles = await p.evaluate(() => document.querySelectorAll('.miles').length);
check(miles === 1, 'one set of milestone cards in the card, got ' + miles);

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
