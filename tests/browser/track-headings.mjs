/*
 * Two programmes, two headings, on the activity library list.
 *
 * The Track column was removed because it repeated the same word down a
 * hundred and forty rows. What replaces it is a heading where one programme
 * ends and the other begins — and that is drawn by script, off a class the
 * server puts on each row, so it can only be checked by drawing it.
 *
 * The rows arrive already in track order, from SQL. This is only about
 * noticing the change and putting the heading in the right place, once.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { readFileSync, writeFileSync } from 'node:fs';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const root = dirname(dirname(here));

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

/* A list table the shape WordPress draws, with the classes the server adds. */
const rows = [
  ['t1', '1', 'Alphabet Handwriting Practice'],
  ['t1', '2', 'Sound Lines'],
  ['t1', '12', 'Blending two sounds'],
  ['t23', '1', 'Reading for meaning'],
  ['t23', '12', 'Sound Search in a real text'],
];
const html = `<!doctype html><meta charset="utf-8">
<style>${readFileSync(root + '/assets/css/bftd-admin.css')}</style>
<body class="wp-admin edit-php post-type-bftd_activity">
<table class="wp-list-table widefat fixed striped">
<thead><tr><td class="check-column"></td><th>No.</th><th>Activity</th><th>Date</th></tr></thead>
<tbody id="the-list">
${rows.map(([t, n, name], i) =>
  `<tr id="post-${100 + i}" class="post-${100 + i} type-bftd_activity bftd-track-${t}">` +
  `<th class="check-column"></th><td>${n}</td><td>${name}</td><td>2026/09/20</td></tr>`).join('\n')}
</tbody></table>
<script src="${here}/jquery.js"></script>
<script>window.BFTD={ajax_url:"",nonce:"",post_id:0,autosave:0,tracks:{"t1":"Track 1","t23":"Track 2 & 3"}};</script>
<script>${readFileSync(root + '/assets/js/bftd-admin.js')}</script>`;

writeFileSync(here + '/track-list.html', html);

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1000, height: 600 } });
const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });

await page.goto('file://' + here + '/track-list.html');
await page.waitForTimeout(250);

const seen = await page.evaluate(() => [...document.querySelectorAll('#the-list tr')].map(tr =>
  tr.classList.contains('bftd-trackhead')
    ? 'HEAD:' + tr.textContent.trim()
    : 'row:' + tr.querySelectorAll('td')[0].textContent.trim()));

check(seen[0] === 'HEAD:Track 1', 'the list opens with the Track 1 heading, got ' + seen[0]);
check(seen[4] === 'HEAD:Track 2 & 3', 'and the other track is headed where it begins, got ' + seen[4]);
check(seen.filter(s => s.startsWith('HEAD:')).length === 2, 'one heading per track, not one per row');
check(seen.filter(s => s.startsWith('row:')).length === 5, 'and every activity is still listed');

/* The heading has to span the table, or it sits in the checkbox column. */
const span = await page.evaluate(() => {
  const th = document.querySelector('.bftd-trackhead th');
  const cols = document.querySelectorAll('thead th, thead td').length;
  return { colspan: parseInt(th.getAttribute('colspan'), 10), cols, width: th.getBoundingClientRect().width,
           table: document.querySelector('table').getBoundingClientRect().width };
});
check(span.colspan === span.cols, 'the heading spans every column, got ' + span.colspan + ' of ' + span.cols);
check(span.width > span.table * 0.9, 'so it reads as a heading rather than a cell');

/* Filtered to one track, there is one heading and it names that track. The
   filter is a page load, so this is a second page rather than rows removed
   from this one. */
writeFileSync(here + '/track-list-one.html',
  html.replace(/<tr id="post-10[012]"[\s\S]*?<\/tr>\n/g, ''));
const only = await browser.newPage({ viewport: { width: 1000, height: 400 } });
await only.goto('file://' + here + '/track-list-one.html');
await only.waitForTimeout(250);
const filtered = await only.evaluate(() => [...document.querySelectorAll('#the-list tr')].map(tr =>
  tr.classList.contains('bftd-trackhead') ? 'HEAD:' + tr.textContent.trim() : 'row'));
check(filtered.filter(s => s.startsWith('HEAD:')).length === 1,
  'filtered to one track there is one heading, got ' + filtered.filter(s => s.startsWith('HEAD:')).length);
check(filtered[0] === 'HEAD:Track 2 & 3', 'and it names the track that is left, got ' + filtered[0]);
await only.close();

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
