/*
 * A session's Appointment on its screen (Karl, 1.213), with the real script:
 * the student's appointments are offered, picking one gives the session its
 * date (and the Calendar's attendance mark for it), New appointment asks for
 * its own date and time, and choosing another student lists theirs.
 */
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname } from 'node:path';
import { chromium, launchOptions } from './pw.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const page_path = execFileSync('php', [here + '/build-session-appt.php']).toString().trim();

let fail = 0;
const check = (ok, msg) => { console.log(`${ok ? '  ok ' : 'FAIL'}  ${msg}`); if (!ok) fail++; };

const browser = await chromium.launch(launchOptions);
const p = await browser.newPage();
const errors = [];
p.on('pageerror', e => errors.push(e.message));
await p.goto('file://' + page_path);
await p.waitForTimeout(150);

const st = () => p.evaluate(() => ({
  options: [...document.querySelectorAll('#bftd-appt-pick option')].map(o => o.value),
  typed: !!document.querySelector('input[name="bftd[session][session_date]"], input[name="bftd[session][session_time]"]'),
  newShown: !document.querySelector('.bftd-appt-new').hidden,
  date: document.querySelector('#bftd_session_session_date').value,
  status: document.querySelector('#bftd_session_status').value,
  note: (document.querySelector('.bftd-from-calendar') || {}).textContent || '',
}));

check(errors.length === 0, 'the admin script loads without errors' + (errors.length ? ': ' + errors[0] : ''));
let s = await st();
check(s.options.length === 4 && s.options[0] === '' && s.options[3] === 'new', 'the student\'s two appointments are offered, and New appointment, got ' + JSON.stringify(s.options));
check(!s.typed, 'there is no typed Date or Time to save: they come from the appointment');
check(!s.newShown, 'New appointment\'s fields wait until it is chosen');

await p.selectOption('#bftd-appt-pick', { index: 1 }); await p.waitForTimeout(150);
s = await st();
check(s.date !== '' && s.status === 'noshow' && /From the Calendar: No show/.test(s.note), 'picking an appointment gives the session its date, and the Calendar\'s mark for it, got ' + JSON.stringify(s));

await p.selectOption('#bftd-appt-pick', 'new'); await p.waitForTimeout(100);
s = await st();
const names = await p.evaluate(() => [...document.querySelectorAll('.bftd-appt-new [name]')].map(x => x.name));
check(s.newShown && ['bftd_appt_new[date]', 'bftd_appt_new[time]', 'bftd_appt_new[minutes]', 'bftd_appt_new[tutor]'].every(n => names.includes(n)), 'New appointment asks for its date, time, minutes and tutor');

await p.selectOption('#bftd_student', '502'); await p.waitForTimeout(150);
s = await st();
check(s.options.length === 3 && /15:00$/.test(s.options[1]) && s.options[2] === 'new', 'choosing another student lists their appointments instead, got ' + JSON.stringify(s.options));

await browser.close();
console.log(fail ? `\n${fail} failure(s)` : '\nAll checks passed.');
process.exit(fail ? 1 : 0);
