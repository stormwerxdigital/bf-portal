/*
 * A skill, written into the activity's own description.
 *
 * All of this is script, so none of it can be checked by reading PHP. Three
 * bugs this session were invisible to every source-level test and obvious the
 * moment a page was driven: a rebuilt select dropping the track off its
 * options, a match count outliving the search it described, and a link that
 * went somewhere no tutor could use. So this one is driven.
 *
 * What has to hold:
 *   it ADDS, never replaces, because the description is somebody's writing;
 *   two skills are two blocks;
 *   the same skill twice is still one block, because re-saving a page and
 *   picking the same skill in two rows both do that.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';

/* Playwright is installed globally in this environment rather than beside the
   plugin, and the plugin ships no node_modules. Resolved through the global
   root so the test runs where it runs and skips cleanly where it does not. */
const require_ = createRequire(import.meta.url);
const { chromium } = require_(
  execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright'
);
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-skill-page.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

/* What the endpoint actually sends: the skill's own content, run through
   wpautop, which is how a reader would see it. Two paragraphs are two
   paragraphs — the endpoint used to send WordPress's stored form, bare lines
   with no tags, and an HTML editor renders that as one run-on block. */
const WORDS = {
  100: { name: 'sounds can be represented by 1-4 letters',
         words: '<p>this is the skill description</p>\n<p>and a second paragraph of it</p>' },
  101: { name: 'Hearing the middle sound in a word', words: '<p>Saying the sound in the middle.</p>' },
};

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 820, height: 800 } });

const errors = [];
page.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });

/* Stands in for the endpoint the script asks for a skill's words. */
await page.route('**/ajax', async route => {
  const id = /skill_id=(\d+)/.exec(route.request().postData() || '')[1];
  await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: WORDS[id] }) });
});

await page.goto('file://' + page_path);
await page.waitForTimeout(250);

const text = () => page.inputValue('#content');
const owned = 'This is the description for activity 1';

check((await text()).includes(owned), 'the description starts as whatever was written in it');

async function chooseLast(term) {
  const boxes = await page.$$('.bftd-skillpull .bftd-actpick-q:visible');
  await boxes[boxes.length - 1].fill(term);
  await page.waitForTimeout(150);
  const hits = await page.$$('.bftd-skillpull .bftd-actpick-r li[data-value]');
  await hits[hits.length - 1].click();
  await page.waitForTimeout(350);
}

await chooseLast('sounds');
let now = await text();
check(now.includes(owned), 'choosing a skill leaves what was already written alone');
check(now.includes('this is the skill description'), 'and adds what that skill says about itself');
check(!now.includes('sounds can be represented by 1-4 letters'),
  'and not the skill\'s name, which is a title in the middle of somebody\'s description');
check(now.indexOf(owned) < now.indexOf('this is the skill description'), 'after it, not over it');

await page.click('.bftd-skillpull button:has-text("Add skill")');
await page.waitForTimeout(250);
await chooseLast('middle');
now = await text();
check(now.includes('this is the skill description') && now.includes('Saying the sound in the middle.'),
  'a second skill adds a second block rather than replacing the first');

/* Picking one that is already in there must not put it in twice. Re-saving a
   page and choosing the same skill in two rows both do exactly this. */
await page.evaluate(() => {
  const sel = document.querySelector('.bftd-skillpull .bftd-actpick-s');
  sel.value = '100';
  jQuery(sel).trigger('change');
});
await page.waitForTimeout(350);
now = await text();
check(1 === (now.match(/this is the skill description/g) || []).length,
  'a skill whose words are already there is not added again');

/* And the box is above the thing it fills. */
const order = await page.evaluate(() => {
  const box = document.querySelector('.bftd-skillpull').getBoundingClientRect();
  const ed  = document.querySelector('#content').getBoundingClientRect();
  return box.top < ed.top;
});
check(order, 'the skills box sits above the description it writes into');

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

/* ---------------------------------------------------------------------------
   The same thing again, with the visual editor in front.

   Everything above drives the text tab, because that is what a bare textarea
   is. No tutor works there. The visual tab is a different branch of the
   script, and it was the broken one: it read the whole description out,
   appended to the string, and set the lot back with setContent(), which does
   not add to a document but replaces it. The editor threw away every node it
   held and re-parsed the markup, so a description came back as the parser's
   version of itself rather than the tutor's.

   The check is not "does it look the same". It is whether the nodes that were
   already in the document are still the same nodes. A property set on an
   element in JavaScript does not survive its parent being rebuilt from an
   HTML string, so it answers that question exactly.
   ------------------------------------------------------------------------ */
const mce_path = page_path.replace('skill-page.html', 'skill-page-mce.html');
const mce = await browser.newPage({ viewport: { width: 820, height: 800 } });
mce.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });
await mce.route('**/ajax', async route => {
  const id = /skill_id=(\d+)/.exec(route.request().postData() || '')[1];
  await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: WORDS[id] }) });
});
await mce.goto('file://' + mce_path);
await mce.waitForTimeout(250);

await mce.evaluate(() => {
  document.querySelectorAll('#fake-mce > *').forEach(el => { el.__keep = true; });
});

const box = await mce.$$('.bftd-skillpull .bftd-actpick-q:visible');
await box[box.length - 1].fill('sounds');
await mce.waitForTimeout(150);
const hit = await mce.$$('.bftd-skillpull .bftd-actpick-r li[data-value]');
await hit[hit.length - 1].click();
await mce.waitForTimeout(350);

const seen = await mce.evaluate(() => ({
  replaced: window.__setContent,
  kept: [...document.querySelectorAll('#fake-mce > *')].filter(el => el.__keep).length,
  heading: !!document.querySelector('#fake-mce > h2'),
  named: document.querySelector('#fake-mce').textContent.includes('sounds can be represented'),
  list: document.querySelectorAll('#fake-mce > ul > li').length,
  paras: [...document.querySelectorAll('#fake-mce > p')].map(p => p.textContent.trim()),
}));

check(0 === seen.replaced, 'the visual editor is added to, not replaced wholesale');
check(3 === seen.kept, 'every block that was already written is the same element afterwards');
check(seen.heading && 2 === seen.list, 'so the heading and the list it had are still there');
check(!seen.named, 'and the skill\'s name is not written into the prose here either');
check(seen.paras.some(t => 'this is the skill description' === t)
   && seen.paras.some(t => 'and a second paragraph of it' === t),
  'and the skill arrives as the paragraphs it was written in, not one run-on line');

check(errors.length === 0, 'and still nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
