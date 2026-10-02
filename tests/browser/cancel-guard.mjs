/*
 * A cancellation cannot be published with nothing said about it.
 *
 * The server holds one as a draft either way, which is the part that cannot
 * be got round. This is the part a tutor actually meets: the button they
 * press, and whether pressing it tells them what is missing or just quietly
 * saves a blank row onto a family's report.
 *
 * Two things here only a browser can answer. Whether the guard is on the
 * publish button and not on Save Draft — a draft is allowed to be half
 * written, and a guard that blocked it would trap somebody mid-sentence. And
 * whether the Publish button still works afterwards: WordPress disables it
 * and starts a spinner the moment the form is submitted, so a submit stopped
 * without undoing that leaves a greyed out button that never comes back, and
 * a screen that looks broken rather than incomplete.
 */
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';

const require_ = createRequire(import.meta.url);
const { chromium } = require_(execFileSync('npm', ['root', '-g']).toString().trim() + '/playwright');

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-session-screen.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });

/* ---- before any script has run ----
 *
 * Whether the questions are on screen used to be a class the script put on
 * <body> once the page had loaded. That is fine right up until the script
 * does not run — blocked, or erroring earlier in the file — and then a
 * cancelled session shows a tutor no way to explain it while the save
 * refuses to publish it, with nothing on screen connecting the two. So PHP
 * decides it from what is stored, and this reads the page with scripts off.
 */
const noJs = await browser.newContext({ javaScriptEnabled: false });
const p0 = await noJs.newPage();
await p0.goto('file://' + page_path);
check(await p0.evaluate(() => document.querySelector('.bftd-onlymissed').offsetParent === null),
  'with no script, an attended session does not ask why it was missed');

/* The same screen for a session already marked rescheduled, as PHP draws it. */
await p0.goto('file://' + page_path.replace('session-screen.html', 'session-screen-moved.html'));
check(await p0.evaluate(() => {
  const el = document.querySelector('.bftd-onlymissed');
  return !!el && el.offsetParent !== null;
}), 'while one already cancelled asks, without waiting for a script to say so');
await noJs.close();

const p = await browser.newPage({ viewport: { width: 900, height: 900 } });
const errors = [];
p.on('pageerror', e => { if (!/sortable is not a function/.test(String(e))) errors.push(String(e)); });
await p.goto('file://' + page_path);
await p.waitForTimeout(250);

const shown = () => p.evaluate(() => document.querySelector('.bftd-onlymissed').offsetParent !== null);
const submits = () => p.evaluate(() => window.__submits);
const warn = () => p.evaluate(() => {
  const el = document.querySelector('.bftd-missed-warn');
  return el ? el.textContent.trim() : '';
});
const reset = () => p.evaluate(() => { window.__submits = 0; });

/* A session that went ahead is asked none of it. */
check(!(await shown()), 'an attended session is not asked why it was missed');
await p.click('#publish');
await p.waitForTimeout(150);
check((await submits()) === 1, 'and publishes without being stopped');

/* Cancelled. */
reset();
await p.selectOption('#bftd_session_status', 'missed');
await p.waitForTimeout(150);
check(await shown(), 'marking it missed asks why');
check(await p.evaluate(() => document.querySelector('#bftd_session_missed_by').getAttribute('aria-required')) === 'true',
  'and says the answer is required, out loud as well as on the page');

await p.click('#publish');
await p.waitForTimeout(200);
check((await submits()) === 0, 'publishing with nothing said is stopped, got ' + (await submits()));
check(/who cancelled this session and why/.test(await warn()), 'and says what is missing, got ' + JSON.stringify(await warn()));

/* The button has to still work. */
check(await p.evaluate(() => {
  const b = document.querySelector('#publish');
  return !b.disabled && !b.classList.contains('disabled');
}), 'the Publish button is not left greyed out');
check(await p.evaluate(() => !document.querySelector('#publishing-action .spinner').classList.contains('is-active')),
  'and the spinner is not left turning');

/* Half an answer is not an answer. */
await p.selectOption('#bftd_session_missed_by', '20');
await p.click('#publish');
await p.waitForTimeout(200);
check((await submits()) === 0, 'a name with no reason is stopped too');

await p.fill('#bftd_session_missed_why', 'Family away');
await p.waitForTimeout(150);
check((await warn()) === '', 'answering both takes the message away');
await p.click('#publish');
await p.waitForTimeout(200);
check((await submits()) === 1, 'and it publishes, got ' + (await submits()));

/* Save Draft is how somebody steps away mid-sentence. It must never be
   blocked, or the guard costs more than the blank row it prevents. */
reset();
await p.fill('#bftd_session_missed_why', '');
await p.selectOption('#bftd_session_missed_by', '');
await p.click('#save-post');
await p.waitForTimeout(200);
check((await submits()) === 1, 'Save Draft is never stopped, got ' + (await submits()));

/* And changing the status back puts the questions away. */
await p.selectOption('#bftd_session_status', 'held');
await p.waitForTimeout(150);
check(!(await shown()), 'putting it back to attended puts the questions away');
reset();
await p.click('#publish');
await p.waitForTimeout(200);
check((await submits()) === 1, 'and it publishes again without them');

check(errors.length === 0, 'and nothing threw: ' + JSON.stringify(errors));

await browser.close();
process.exit(fail ? 1 : 0);
