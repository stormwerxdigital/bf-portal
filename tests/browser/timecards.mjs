/*
 * The time card screen, driven.
 *
 * Two claims here cannot be checked anywhere else, because both are claims
 * about what is on a page.
 *
 * A TUTOR SEES THEIR OWN CARD AND NOTHING ELSE. Not a filter set to them,
 * which is a query string somebody can edit. The check is that no other
 * tutor's name, no other tutor's student and no control that decides money
 * is anywhere in the document they are served.
 *
 * AN APPROVER CAN FIND WHAT NEEDS THEM. A fortnight is mostly routine, and
 * the two or three rows that are not have to be findable without reading
 * every line. They are marked on the row itself.
 *
 * And one that is easy to get wrong in a template: money has to be the
 * frozen amount on the entry, not a rate looked up again at render time,
 * which is how an approved row silently restates itself after a raise.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');
const here = dirname(fileURLToPath(import.meta.url));

execFileSync('php', [join(here, 'build-timecards.php')], { stdio: 'pipe' });

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const errors = [];

const open = async (file) => {
  const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  p.on('pageerror', e => errors.push(String(e)));
  await p.goto('file://' + join(here, file));
  await p.waitForTimeout(150);
  return p;
};

/* ---------------------------------------------------------------- approver */
const boss = await open('timecards.html');

const names = await boss.$$eval('.bftd-tc-h h2', hs => hs.map(h => h.textContent.trim()));
check(names.includes('Laurel Sanders') && names.includes('Nina Okafor'),
  'an approver sees a section for each tutor with work in the period');
check(!names.includes('Karl Sanders'),
  'and not one for somebody who has never been given pay terms');

/* Sam has never been given pay terms and has taught a session anyway. His
   work is still on the screen, because the alternative is money on the
   ledger that no screen shows, which is the worst failure this screen has. */
const sam = await boss.$$eval('.bftd-tc-sec', secs => {
  const s = secs.find(x => /Sam Idowu/.test(x.querySelector('h2').textContent));
  return s ? [...s.querySelectorAll('tbody tr')].length : 0;
});
check(1 === sam, 'a tutor with entries but no terms set still gets their work shown');

const pills = await boss.$$eval('.bftd-tc-h .bftd-pill', ps => ps.map(p => p.textContent.trim()));
check(pills.some(p => /Employee/.test(p)) && pills.some(p => /Contractor/.test(p)),
  'and each tutor is labelled with how they are engaged, which decides what comes off their pay');

const flagged = await boss.$$eval('.bftd-tc-row.is-flagged td:nth-child(3)', tds => tds.map(t => t.textContent.trim()));
check(2 === flagged.length, 'the two rows that need a decision are marked as rows');
check(flagged.every(t => /needs a decision/.test(t)), 'and say so in words as well as in colour');
check(flagged.some(t => /Cancelled/.test(t)) && flagged.some(t => /Rescheduled/.test(t)),
  'which are the cancelled one and the moved one');

/* The mark is on the row, not only in the last cell, because an approver
   scans down an edge rather than reading across every line. */
const edge = await boss.$eval('.bftd-tc-row.is-flagged', el => getComputedStyle(el).boxShadow);
check(edge && 'none' !== edge, 'and the mark is on the row itself');

const money = await boss.$$eval('.bftd-tc-sec', secs => {
  const s = secs.find(x => /Laurel Sanders/.test(x.querySelector('h2').textContent));
  return [...s.querySelectorAll('tbody .bftd-tc-r')].map(td => td.textContent.trim());
});
check(money.includes('$150.00'), 'a diagnostic is paid at the diagnostic rate');
check(money.filter(m => '$45.00' === m).length === 4, 'and four sessions at that tutor\'s own session rate');

const head = await boss.$eval('.bftd-tc-sec .bftd-tc-sum', el => el.textContent.replace(/\s+/g, ' ').trim());
check(/\$195\.00 approved/.test(head), 'the heading totals only what has been approved');
check(/\$135\.00 waiting/.test(head), 'and says separately what is still waiting on somebody');

check(0 < (await boss.$$('input[name="entry[]"]')).length, 'an approver has something to tick');
check(0 < (await boss.$$('button[value="approved"]')).length, 'and something to press');

/* ------------------------------------------- the yearly CRA reminder ------ */
/* The rates change every 1st of January and nothing in here can tell that it
   is working from last year's. The reminder is the first thing on the screen
   until an approver says they have looked. Checked by rendering, because a
   notice can be built, hooked and still output nothing. */
const cra = await boss.$('.bftd-cra-notice');
check(null !== cra, 'the CRA reminder is on the approver\'s screen');
if (cra) {
  const words = await boss.$eval('.bftd-cra-notice', el => el.innerText.replace(/\s+/g,' '));
  check(/CPP/.test(words) && /EI/.test(words), 'and names the figures that change');
  check(/calculator/.test(words), 'and points at the CRA\'s own calculator');
  const ack = await boss.$('.bftd-cra-notice a.button');
  check(null !== ack, 'with something to press once it has been checked');
  const href = ack ? await boss.$eval('.bftd-cra-notice a.button', a => a.getAttribute('href')) : '';
  check(/_wpnonce=/.test(href), 'and the acknowledgement carries a nonce');
  check(/bftd_cra_ack=\d{4}/.test(href), 'and names the year it is acknowledging');

  /* It must sit above the cards. A reminder below a fortnight of rows is a
     reminder nobody scrolls to. */
  const order = await boss.evaluate(() => {
    const n = document.querySelector('.bftd-cra-notice');
    const c = document.querySelector('.bftd-tc-sec');
    if (!n || !c) return 'missing';
    return (n.compareDocumentPosition(c) & Node.DOCUMENT_POSITION_FOLLOWING) ? 'above' : 'below';
  });
  check('above' === order, 'and sits above the cards rather than under them');
}

/* ------------------------------------------------------------------- tutor */
const mine = await open('timecard-mine.html');

const heading = await mine.$eval('h1', h => h.textContent.trim());
check('My Time Card' === heading, 'a tutor gets their own card, named as theirs');

const text = await mine.evaluate(() => document.body.innerText);
check(/Laurel Sanders/.test(text), 'with their own name on it');
check(!/Nina Okafor/.test(text) && !/Sam Idowu/.test(text), 'and no sign of anybody else');
check(null === await mine.$('.bftd-cra-notice'),
  'and no CRA reminder, because deciding the deductions is not a tutor\'s job');
check(/Kaine M/.test(text), 'their own students are named');

check(0 === (await mine.$$('input[name="entry[]"]')).length, 'nothing to tick');
check(0 === (await mine.$$('button[value="approved"]')).length, 'nothing to approve');
check(0 === (await mine.$$('button[value="declined"]')).length, 'nothing to decline');
check(0 === (await mine.$$('.bftd-tc-only')).length, 'and no way to ask about another tutor');

/* The state of their own work is the thing they came for. */
const states = await mine.$$eval('.bftd-tc-row .bftd-pill', ps => ps.map(p => p.textContent.trim()));
check(states.includes('Approved') && states.includes('Pending'),
  'they can see what has been signed off and what has not');

/* ------------------------------------------------------------ the example */
const ex = await open('timecard-sample.html');

const exText = await ex.evaluate(() => document.body.innerText);
check(/Nobody is paid for anything on this page/.test(exText),
  'the worked example says at the top that it is not real');
check(!/Laurel Sanders/.test(exText) && !/Nina Okafor/.test(exText) && !/Sam Idowu/.test(exText),
  'and carries nobody\'s real name, so a screenshot of it cannot be mistaken for a card');

/* An approver is looking at this one, and there is still nothing to press:
   a sample with working buttons is a sample somebody eventually approves. */
check(0 === (await ex.$$('input[name="entry[]"]')).length, 'there is nothing to tick on it');
check(0 === (await ex.$$('button[value="approved"]')).length, 'and nothing to approve');
check(0 === (await ex.$$('form')).length, 'because there is no form on it at all');

const exKinds = await ex.$$eval('.bftd-tc-row td:nth-child(2)', tds => tds.map(t => t.textContent.trim()));
check(['Session', 'Reading diagnostic', 'Rescheduled session', 'Cancelled session']
  .every(k => exKinds.some(t => t.startsWith(k))),
  'every kind of row a card can hold is on it');

const exStates = await ex.$$eval('.bftd-tc-row .bftd-pill', ps => ps.map(p => p.textContent.trim()));
check(['Pending', 'Approved', 'Declined', 'Paid'].every(k => exStates.includes(k)),
  'and every state a row can be in, which is what a real fortnight rarely shows at once');

check(0 < (await ex.$$('.bftd-tc-key dt')).length, 'with each of them explained underneath');

/* ------------------------------------------------ a statutory holiday */
const stat = await open('timecard-stat.html');

check(1 === (await stat.$$('.bftd-tc-stat')).length, 'a period holding a statutory holiday says so above the cards');
const statHead = await stat.$eval('.bftd-tc-stat-h', el => el.textContent.replace(/\s+/g, ' ').trim());
check(/Christmas Day/.test(statHead), 'naming the holiday');
check(/Friday 25 December 2026/.test(statHead), 'and the day it falls on');

/* The picker has to be showing the period whose rows are underneath it. */
const picked = await stat.$eval('#bftd-tc-period', el => el.options[el.selectedIndex].textContent.trim());
check(/Dec 2026/.test(picked), 'the period picker is showing the period on screen, not the first one it knows');

const yes = await stat.$eval('.bftd-tc-stat-t tr.is-yes', el => el.textContent.replace(/\s+/g, ' ').trim());
check(/Laurel Sanders/.test(yes), 'the tutor who qualified is marked as qualifying');
check(/24 of the thirty days/.test(yes), 'with the day count that got them there');
check(/\$45\.00/.test(yes), 'and an average day\'s pay');
check(/taught 1 hour on the day/.test(yes), 'and, having taught it, how long for');
check(/for working it/.test(yes), 'and that the total includes a premium for having worked it');

const no = await stat.$eval('.bftd-tc-stat-t tr.is-no', el => el.textContent.replace(/\s+/g, ' ').trim());
check(/Nina Okafor/.test(no), 'the contractor is shown too');
check(/entitlement of employees/.test(no), 'with the reason, rather than simply being left off the list');
check(/Nothing/.test(no), 'and nothing owed');

check(0 < (await stat.$$('button:has-text("Put these on the time cards")')).length,
  'and an approver has a button to turn the calculation into entries');
check(/Safe to press twice/.test(await stat.evaluate(() => document.body.innerText)),
  'which says that pressing it twice is safe, because somebody will');

check(0 === errors.length, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
