/*
 * Searching the activity and skill libraries.
 *
 * A hundred and forty activities per track, and a tutor who half remembers
 * one. Three ways the first version of this let them down, all of which read
 * to the tutor as the search being broken rather than as the search being
 * literal.
 *
 * A NUMBER WAS NOT A NUMBER. The label reads "12 · Blending three sounds"
 * and the search matched the whole label as text, so typing 12 returned 112,
 * 120, 121 and anything with those digits in its title. Six answers to a
 * question that has one.
 *
 * WORD ORDER MATTERED. "sound lines" found it and "lines sound" found
 * nothing, which is the same request typed by somebody in a hurry.
 *
 * AND THE EIGHT SHOWN WERE THE FIRST EIGHT, not the best eight. On a library
 * this long that meant the thing being searched for could be pushed off the
 * end of its own result by worse matches that happened to sit above it.
 *
 * Driven, because every word of it is script.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const here = dirname(fileURLToPath(import.meta.url));
execFileSync('php', [join(here, 'build-picker.php')], { stdio: 'pipe' });

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!fail && !ok) {} if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 900, height: 900 } });
const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });
await page.goto('file://' + join(here, 'picker.html'));
await page.waitForTimeout(250);

async function search(where, term) {
  await page.fill(`${where} .bftd-actpick-q`, term);
  await page.waitForTimeout(140);
  return {
    hits: await page.$$eval(`${where} .bftd-actpick-r li[data-value]`, ls => ls.map(l => l.textContent.trim())),
    said: await page.$eval(`${where} .bftd-actpick-n`, el => el.textContent.trim()).catch(() => ''),
    inField: await page.$$eval(`${where} .bftd-actpick-s option`, os =>
      os.map(o => o.textContent.trim()).filter(t => !/^Choose /.test(t))),
  };
}

/* ---------------------------------------------------------- by number */
let r = await search('#acts', '12');
check(r.hits[0].startsWith('12 · '), 'typing a number puts that number first');
check(r.hits.filter(h => h.startsWith('12 · ')).length === 2, 'both activities numbered twelve are offered');
check(!r.hits.some(h => h.startsWith('112 · ')), 'and 112 is not, because one hundred and twelve is not twelve');
check(r.hits.some(h => h.startsWith('120 · ')), 'while 120 is, under them, since somebody may be typing a longer number');

r = await search('#acts', '17');
check(r.hits[0].startsWith('17 · ') && r.hits[1].startsWith('17 · '),
  'both activities numbered seventeen come first, even though 170 sits between them in the library');
check(r.hits[2].startsWith('170 · '), 'and 170 comes after them rather than in among them');

r = await search('#acts', '3');
check(r.hits[0].startsWith('3 · '), 'a single digit still finds its own activity first');

/* A number and a word together, which is how somebody half remembering one
   actually types. */
r = await search('#acts', '17 phoneme');
check(1 === r.hits.length && /Phoneme Deletion/.test(r.hits[0]),
  'a number and a word together narrow to the one that is both');

r = await search('#acts', '17 nonsense');
check(0 === r.hits.length, 'and a number with a word that does not match it finds nothing');

/* -------------------------------------------------------- word order */
const forward = await search('#acts', 'sound lines');
const back    = await search('#acts', 'lines sound');
check(forward.hits.length > 0, 'two words in the order they are written find it');
check(JSON.stringify(forward.hits) === JSON.stringify(back.hits),
  'and the same two words in the other order find exactly the same things');
check(forward.hits[0] === '1 · Sound Lines',
  'with the one actually called that first, ahead of the one merely containing it');

const skf = await search('#skills', 'middle sound');
const skb = await search('#skills', 'sound middle');
check(JSON.stringify(skf.hits) === JSON.stringify(skb.hits), 'the same holds in the skills library');
check(skf.hits.length > 0, 'which does find it');

/* ------------------------------------------------------------ ranking */
r = await search('#acts', 'blending');
check(r.hits[0] === '3 · Blending two sounds',
  'a name beginning with the word beats one merely containing it, even when the other is first in the library');
check(r.hits.some(h => /Warm up before Blending/.test(h)), 'which is still offered, underneath');

r = await search('#acts', 'read');
check(r.hits.some(h => /Read, Read Back/.test(h)),
  'a word where a word begins is found');

/* ------------------------------------------------ the two lists agree */
for (const term of ['12', 'sound', 'blending', 'read', '1']) {
  const s = await search('#acts', term);
  const n = parseInt(s.said, 10);
  check(!isNaN(n) && n === s.inField.length,
    `"${term}": the count says ${s.said} and the field under it holds ${s.inField.length}`);
  check(s.hits.every(h => s.inField.includes(h)),
    `"${term}": everything in the short list is in the field under it`);
}

/* ------------------------------------------------------- nothing at all */
r = await search('#acts', 'zzzz');
check(0 === r.hits.length, 'a search that matches nothing shows nothing');
check(/Nothing matches/.test(r.said), 'and says so, because an empty list looks the same as a broken one');

r = await search('#acts', '');
check(0 === r.hits.length, 'an empty box suggests nothing');

/* --------------------------------------- numbers where there are none */
r = await search('#skills', '1');
check(r.hits.every(h => /1/.test(h)),
  'in a library that numbers nothing, digits are read as characters again');

/* ---------------------------------------- every match, numbered or not */
r = await search('#acts', 'listen tally say write');
check(r.hits.filter(h => /Listen, Tally, Say, Write$/.test(h)).length === 10,
  'a name ten activities share offers all ten, got ' + r.hits.filter(h => /Listen, Tally, Say, Write$/.test(h)).length);
check(r.hits.filter(h => 'Listen, Tally, Say, Write' === h).length === 2,
  'including the two that have no number yet');
check(!(await page.$$eval('#acts .bftd-actpick-r li', ls => ls.some(l => /Keep typing/.test(l.textContent)))),
  'and does not stop at eight and ask for more typing that could not help');
r = await search('#skills', 'reading');
check(r.hits.length === 4 && r.hits.every(h => /^Reading/.test(h)),
  'an unnumbered skill library is searched by its words, got ' + JSON.stringify(r.hits));

check(0 === errors.length, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
